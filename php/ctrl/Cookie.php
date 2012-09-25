<?php

$slash = explode('/', getenv('SCRIPT_NAME'));
$app = getenv('DOCUMENT_ROOT') . '/' . $slash[1] . '/';

require_once($app . "php/utilities/general.php");
require_once($app . 'php/inc/cookie.inc.php');

if (!isset($_SESSION)) {
    session_start();
 }

try {
    $cookie = new Cookie();
    $cookie->validate();
    $uri = (isset($_REQUEST['originating_uri']) ? $_REQUEST['originating_uri'] : 'index.php');
}   
catch (AuthException $e) {
    header('HTTP/1.0 401 ' . $e->getMessage());
    die($e->getMessage());
}

try {
    if (isset($_REQUEST['change_role_to'])) {
        $new_role = $_REQUEST['change_role_to'];
        $fp = configuration_vars::get_instance()->forbidden_pages;
        if (!$uri || 
            (isset($_SESSION['userdata']) && isset($fp[$new_role]))) {
            $forbidden = false;
            foreach($fp[$new_role] as $page) {
                if (strpos($uri, $page) !== false) {
                    $forbidden = true;
                    break;
                }
            }
            if ($forbidden) 
                $uri = 'index.php';
        }
        $cookie->change_role($new_role);
        printXML('<navigation>' . $uri . '</navigation>');
        exit;
    }
    
    if (isset($_REQUEST['change_lang_to'])) {
        $new_lang = $_REQUEST['change_lang_to'];
        $cookie->change_language($new_lang);
        printXML('<navigation>' . $uri . '</navigation>');
        exit;        
    }
}

catch(Exception $e) {
  header('HTTP/1.0 401 ' . $e->getMessage());
  die($e->getMessage());
}  


?>