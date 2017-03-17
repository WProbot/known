<?php

    namespace IdnoPlugins\IndiePub\Pages\MicroPub {

        use Idno\Common\ContentType;
        use Idno\Entities\User;
        use IdnoPlugins\IndiePub\Pages\IndieAuth\Token;

        use DOMDocument;
        use DOMXPath;

        class Endpoint extends \Idno\Common\Page
        {

            /**
             * Fetch syndication endpoints from Convoy.
             *
             * @param array $account_strings flat list of syndication
             *   IDs
             * @param array $account_data list of complex account data
             *   conforming to
             *   http://micropub.net/draft/#syndication-targets
             */
            private function getServiceAccountsFromHub(&$account_strings, &$account_data)
            {
                if (\Idno\Core\Idno::site()->hub()) {
                    $result = \Idno\Core\Idno::site()->hub()->makeCall('hub/user/syndication', [
                        'content_type' => 'note',
                    ]);

                    if (!empty($result['content'])) {
                        $content = $result['content'];

                        // parse value from the inputs with name="syndication[]".
                        // TODO consider serving JSON in addition to HTML from hub?
                        $doc = new DOMDocument();
                        $doc->loadHTML($content);
                        $toggles = (new DOMXPath($doc))->query('//*[@name="syndication[]"]');

                        foreach ($toggles as $toggle) {
                            $uid  = $toggle->getAttribute('value');

                            $account = strip_tags($toggle->getAttribute('data-on'));
                            $service = ucwords(explode('::', $uid, 2)[0]);

                            $name =  "$account on $service";
                            $name = trim(preg_replace('/\s+/u', ' ', $name));

                            $account_strings[] = $uid;
                            $account_data[]    = ['uid' => $uid, 'name' => $name];
                        }
                    }
                }
            }

            function get($params = array())
            {
                $this->gatekeeper();
                if ($query = trim($this->getInput('q'))) {
                    switch ($query) {
                    case 'syndicate-to':
                        $account_strings = \Idno\Core\Idno::site()->syndication()->getServiceAccountStrings();
                        $account_data    = \Idno\Core\Idno::site()->syndication()->getServiceAccountData();
                        $this->getServiceAccountsFromHub($account_strings, $account_data);

                        if ($this->isAcceptedContentType('application/json')) {
                            header('Content-Type: application/json');
                            echo json_encode([
                                'syndicate-to' => $account_data,
                            ], JSON_PRETTY_PRINT);
                        } else {
                            echo http_build_query([
                                "syndicate-to" => $account_strings,
                            ]);
                        }
                        break;
                    }
                }
            }

            function post()
            {
                //fail-by-default in case of unhandled errors
                $this->setResponse(500);

                $this->gatekeeper();
                // If we're here, we're authorized

                \Idno\Core\Idno::site()->triggerEvent('indiepub/post/start', ['page' => $this]);

                $action = $this->getInput('action', 'create');
                switch ($action) {
                case 'create':
                    $this->postCreate();
                    break;
                case 'delete':
                    $this->postDelete();
                    break;
                default:
                    $this->error(501, 'not_implemented', 'Action not implemented');
                }
            }

            function postCreate()
            {

                // Get details
                $type        = $this->getInput('h', 'entry');
                if ($type == 'annotation') {
                    return $this->postCreateAnnotation();
                }

                $content     = $this->getInput('content');
                $name        = $this->getInput('name');
                $in_reply_to = $this->getInput('in-reply-to');
                $syndicate   = $this->getInput('mp-syndicate-to', $this->getInput('syndicate-to'));
                $posse_links = $this->getInput('syndication');
                $bookmark_of = $this->getInput('bookmark-of');
                $like_of     = $this->getInput('like-of');
                $repost_of   = $this->getInput('repost-of');
                $categories  = $this->getInput('category');
                $rsvp        = $this->getInput('rsvp');
                $mp_type     = $this->getInput('mp-type');
                if (!empty($mp_type)) {
                   $type = $mp_type;
                }
                if (is_string($categories)) {
                    $categories = (array) $categories;
                }
                if (is_string($posse_links)) {
                    $posse_links = (array) $posse_links;
                }

                if ($type == 'entry') {
                    $type = 'note';

                    if (!empty($_FILES['photo'])) {
                        $type = 'photo';
                    } else if ($photo_url = $this->getInput('photo')) {
                        $type      = 'photo';
                        $success   = $this->uploadFromUrl('photo', $photo_url);
                        if (!$success) {
                            \Idno\Core\Idno::site()->triggerEvent('indiepub/post/failure', ['page' => $this]);
                            $this->error(
                                400, 'invalid_request',
                                "Failed uploading photo from $photo_url"
                            );
                        }
                    }

                    if (!empty($_FILES['video'])) {
                        $type = 'video';
                    } else if ($video_url = $this->getInput('video')) {
                        $type      = 'video';
                        $success   = $this->uploadFromUrl('video', $video_url);
                        if (!$success) {
                            \Idno\Core\Idno::site()->triggerEvent('indiepub/post/failure', ['page' => $this]);
                            $this->setResponse(500);
                            echo "Failed uploading video from $video_url";
                            exit;
                        }
                    }

                    if (!empty($_FILES['audio'])) {
                        $type = 'audio';
			$_FILES['media'] = $_FILES['audio'];
                    } else if ($audio_url = $this->getInput('audio')) {
                        $type      = 'audio';
                        $success   = $this->uploadFromUrl('audio', $audio_url);
                        if (!$success) {
                            \Idno\Core\Idno::site()->triggerEvent('indiepub/post/failure', ['page' => $this]);
                            $this->setResponse(500);
                            echo "Failed uploading audio from $audio_url";
                            exit;
                        }
                    }

                    if ($type == 'note' && !empty($name)) {
                        $type = 'article';
                    }
                }
                if ($type == 'checkin')  {
                    $place_name = $this->getInput('place_name');
                    $location = $this->getInput('location');
                    $photo = $this->getInput('photo');
                    $latlong = explode(",",$location);
                    $lat = str_ireplace("geo:", "", $latlong[0]);
                    $long = $latlong[1];
                    $q = \IdnoPlugins\Checkin\Checkin::queryLatLong($lat, $long);
                    $user_address = $q['display_name'];
                    if (!empty($_FILES['photo'])) {
                        $id = \Idno\Entities\File::createFromFile($_FILES['photo']['tmp_name'], $_FILES['photo']['name'], $_FILES['photo']['type']) ;
                        $photo = \Idno\Core\Idno::site()->config()->url . 'file/' . $id;
                    }
                    if (!empty($photo)) {
                        $htmlPhoto = '<p><img style="display: block; margin-left: auto; margin-right: auto;" src="' . $photo . '" alt="' . $place_name . '"  /></p>';
                    }
                }
                if (($type == 'photo' || $type == 'video' || $type == 'audio') && empty($name) && !empty($content)) {
                    $name    = $content;
                    $content = '';
                }
                if (!empty($bookmark_of)) {
                    $type = 'bookmark';
                }
                if (!empty($like_of)) {
                    $type = 'like';
                }
                if (!empty($repost_of)) {
                    $type = 'repost';
                }
                if (!empty($rsvp)) {
                    $type = 'rsvp';
                }

                // setting all categories as hashtags into content field
                if (is_array($categories)) {
                    $hashtags = "";
                    foreach ($categories as $category) {
                        $category = trim($category);
                        if ($category) {
                            if (str_word_count($category) > 1) {
                                $category = str_replace("'"," ",$category);
                                $category = ucwords($category);
                                $category = str_replace(" ","",$category);
                            }
                            $hashtags .= " #$category";
                        }
                    }
                    $title_words = explode(" ", $name);
                    $name = "";
                    foreach ($title_words as $word) {
                        if (substr($word,0,1) !== "#") {
                            $name .= "$word ";
                        }
                    }
                }

                // Get an appropriate plugin, given the content type
                if ($contentType = ContentType::getRegisteredForIndieWebPostType($type)) {
                    if ($entity = $contentType->createEntity()) {
                        if (is_array($content)) {
                            $content_value = '';
                            if (!empty($content['html'])) {
                                $content_value = $content['html'];
                            } else if (!empty($content['value'])) {
                                $content_value = htmlspecialchars($content['value']);
                            }
                        } else {
                            $content_value = htmlspecialchars($content);
                        }
                        if (is_array($posse_links) && count($posse_links) > 0) {
                            foreach ($posse_links as $posse_link) {
                                if (!empty($posse_link)) {
                                    $posse_service = preg_replace('/^(www\.|m\.)?(.+?)(\.com|\.org|\.net)?$/', '$2', parse_url($posse_link, PHP_URL_HOST));
                                    $entity->setPosseLink($posse_service, $posse_link, '', '');
                                }
                            }
                        }
                        $hashtags = (empty($hashtags) ? "" : "<p>".$hashtags."</p>");
                        $htmlPhoto    = (empty($htmlPhoto) ? "" : "<p>".$htmlPhoto."</p>");
                        $this->setInput('title', $name);
                        $this->setInput('body', $htmlPhoto.$content_value.$hashtags);
                        $this->setInput('inreplyto', $in_reply_to);
                        $this->setInput('bookmark-of', $bookmark_of);
                        $this->setInput('like-of', $like_of);
                        $this->setInput('repost-of', $repost_of);
                        $this->setInput('rsvp', $rsvp);
                        $this->setInput('access', 'PUBLIC');
                        if ($type ==  'checkin') {
                            $this->setInput('lat', $lat);
                            $this->setInput('long', $long);
                            $this->setInput('user_address', $user_address);
                            $this->setInput('placename',$place_name);
                        }
                        if ($created = $this->getInput('published')) {
                            $this->setInput('created', $created);
                        }
                        if (!empty($syndicate)) {
                            if (is_array($syndicate)) {
                                $syndication = $syndicate;
                            } else {
                                $syndication = array(trim(str_replace('.com', '', $syndicate)));
                            }
                            \Idno\Core\Idno::site()->logging()->info("Setting syndication: $syndication");
                            $this->setInput('syndication', $syndication);
                        }
                        if ($entity->saveDataFromInput()) {
                            \Idno\Core\Idno::site()->triggerEvent('indiepub/post/success', ['page' => $this, 'object' => $entity]);
                            $this->setResponse(201);
                            header('Location: ' . $entity->getURL());
                            exit;
                        } else {
                            \Idno\Core\Idno::site()->triggerEvent('indiepub/post/failure', ['page' => $this]);
                            $this->error(
                                400, 'invalid_request',
                                "Couldn't create {$type}"
                            );
                        }

                    }

                } else {
                    \Idno\Core\Idno::site()->triggerEvent('indiepub/post/failure', ['page' => $this]);
                    $this->error(
                        400, 'invalid_request',
                        "Couldn't find content type {$type}"
                    );
                }
            }

            /**
             * Add a "like" or a "reply" to a post
             */
            function postCreateAnnotation()
            {
                $url       = $this->getInput('url');
                $content   = $this->getInput('content');
                $type      = $this->getInput('type');
                $username  = $this->getInput('username');
                $userurl   = $this->getInput('userurl');
                $userphoto = $this->getInput('userphoto');

                $notEmpty = array('url', 'type', 'username', 'userurl', 'userphoto');
                foreach ($notEmpty as $varName) {
                    if ($$varName == '') {
                        $this->error(
                            400, 'invalid_request',
                            '"' . $varName . '" must not be empty'
                        );
                    }
                }
                if (!filter_var($url, FILTER_VALIDATE_URL)) {
                    $this->error(400, 'invalid_request', 'URL is invalid');
                }
                $entity = \Idno\Common\Entity::getByUUID($url);
                if ($entity === false) {
                    $this->error(400, 'not_found');
                }

                if ($type !== 'like' && $type !== 'reply') {
                    $this->error(
                        400, 'invalid_request',
                        'Annotation type must be "like" or "reply"'
                    );
                }
                if ($type === 'reply' && $content == '') {
                    $this->error(
                        400, 'invalid_request', '"content" must not be empty'
                    );
                }
                if (!filter_var($userurl, FILTER_VALIDATE_URL)) {
                    $this->error(400, 'invalid_request', '"userurl" is invalid');
                }
                if (!filter_var($userphoto, FILTER_VALIDATE_URL)) {
                    $this->error(400, 'invalid_request', '"userphoto" is invalid');
                }

                $ok = $entity->addAnnotation(
                    $type, $username, $userurl, $userphoto, $content
                );
                if (!$ok) {
                    $this->error(
                        500, 'internal_error',
                        'Saving annotation failed'
                    );
                }
                $entity->save();
                $this->setResponse(204);
                exit();
            }

            function postDelete()
            {
                $url = $this->getInput('url');
                if (!filter_var($url, FILTER_VALIDATE_URL)) {
                    \Idno\Core\Idno::site()->triggerEvent('indiepub/post/failure', ['page' => $this]);
                    $this->error(400, 'invalid_request', 'URL is invalid');
                }

                $entity = \Idno\Common\Entity::getByUUID($url);
                if ($entity === false) {
                    \Idno\Core\Idno::site()->triggerEvent('indiepub/post/failure', ['page' => $this]);
                    $this->error(400, 'not_found');
                }

                $owner       = $entity->attributes['owner'];
                $currentUser = \Idno\Core\Idno::site()->session()->currentUserUUID();
                if ($owner !== $currentUser) {
                    \Idno\Core\Idno::site()->triggerEvent('indiepub/post/failure', ['page' => $this, 'object' => $entity]);
                    $this->error(403, 'forbidden');
                }

                $entity->delete();
                $this->setResponse(204);
                \Idno\Core\Idno::site()->triggerEvent('indiepub/post/success', ['page' => $this, 'object' => $entity]);
                exit();
            }

            /**
             * Reply with a micropub error response and exit.
             *
             * @param int    $statusCode  HTTP status code
             * @param string $error       Micropub error code
             * @param string $description Human-readable error description
             *
             * @return void
             */
            protected function error($statusCode, $error, $description)
            {
                $site = \Idno\Core\Idno::site();
                $msgs = $site->session()->getMessages();
                foreach ($msgs as $msg) {
                    if ($msg['message_type'] == 'alert-danger') {
                        $description .= ': ' . $msg['message'];
                    }
                }

                $this->setResponse($statusCode);
                $tplVars = array(
                    'error'             => $error,
                    'error_description' => $description,
                );
                $site->template()->setTemplateType('json');
                $site->template()->__($tplVars)->drawPage();
                exit();
            }

            /**
             * Override normal "forbidden" page
             */
            function deniedContent($title = '')
            {
                $this->error(403, 'forbidden', $title);
            }

            /**
             * Micropub optionally allows uploading files from a
             * URL. This method downloads the file at a URL to a
             * temporary location and puts it in the php $_FILES
             * array.
             *
             * @param string $type "photo", "audio" or "video"
             * @param string $url  File URL
             */
            private function uploadFromUrl($type, $url)
            {
                $pathinfo = pathinfo(parse_url($url, PHP_URL_PATH));
                switch ($pathinfo['extension']) {
                case 'jpg':
                case 'jpeg':
                    $mimetype = 'image/jpeg';
                    break;
                case 'png':
                    $mimetype = 'image/png';
                    break;
                case 'gif':
                    $mimetype = 'image/gif';
                    break;

                case 'mp4':
                    $mimetype = 'video/mp4';
                    break;
                case 'ogv':
                    $mimetype = 'video/ogg';
                    break;

                case 'mp3':
                    $mimetype = 'audio/mpeg';
                    break;
                case 'oga':
                case 'ogg':
                    $mimetype = 'audio/ogg';
                    break;
                case 'wav':
                    $mimetype = 'audio/x-wav';
                    break;
                }

                $tmpname  = tempnam(sys_get_temp_dir(), 'indiepub_');
                $fp       = fopen($url, 'rb');
                if ($fp) {
                    $success = file_put_contents($tmpname, $fp);
                    fclose($fp);
                }
                if ($success) {
                    $_FILES[$type] = [
                        'tmp_name' => $tmpname,
                        'name'     => $pathinfo['basename'],
                        'size'     => filesize($tmpname),
                        'type'     => $mimetype,
                    ];
                }
                return $success;
            }

        }
    }
