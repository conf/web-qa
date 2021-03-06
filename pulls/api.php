<?php
$errors = array();
require('./config.php');

if ($_SERVER['SERVER_NAME'] === 'schlueters.de') {
	define('DEV', true);
	error_reporting(-1);
	ini_set('display_errors', 1);
} else {
	define('DEV', false);
}

function verify_password($user, $pass)
{
    global $errors;

    $post = http_build_query(
            array(
                    'token' => getenv('AUTH_TOKEN'),
                    'username' => $user,
                    'password' => $pass,
            )
    );
 
    $opts = array(
            'method'        => 'POST',
            'header'        => 'Content-type: application/x-www-form-urlencoded',
            'content'       => $post,
    );

    $ctx = stream_context_create(array('http' => $opts));

    $s = @file_get_contents('https://master.php.net/fetch/cvsauth.php', false, $ctx);

    $a = @unserialize($s);
    if (!is_array($a)) {
            $errors[] = "Failed to get authentication information.\nMaybe master is down?\n";
            return false;
    }
    if (isset($a['errno'])) {
            $errors[] = "Authentication failed: {$a['errstr']}\n";
            return false;
    }

    return true;
}

function verify_password_DEV($user, $pass)
{
	global $errors;
	$errors[] = "Unknown user $user (DEV)";
	return $user === 'johannes';
}

function do_http_request($url, $opts)
{
	global $errors;

	$ctxt = stream_context_create(array('http' => $opts));
	$actual_url = str_replace('https://', 'https://'.GITHUB_USER.':'.GITHUB_PASS.'@', $url);

	$old_track_errors = ini_get('track_errors');
	ini_set('track_errors', true);
	$s = @file_get_contents($actual_url, false, $ctxt);
	ini_set('track_errors', $old_track_errors);

	if (isset($_SESSION['debug']['requests'])) {
		$_SESSION['debug']['requests'][] = array(
			'url' => $url,
			'opts'=> $opts,
			'headers' => $http_response_header,
			'response' => $s
		);
	}

	if (!$s) {
		$errors[] = "Server responded: ".$http_response_header[0];
		$errors[] = "Github user: ".GITHUB_USER;
		if ($_SESSION['user'] === 'johannes') {
			/* This might include the password or such, so not everybody should get it
			   The good news is that the HTTP Status code usually is a good enough hint
			*/
			$errors[] = $php_errormsg;
		}
		return false;
	}
	return $s;
}

function ghpostcomment($pull, $comment)
{
	global $errors;

	$post = json_encode(array("body" => "**Comment on behalf of $_SESSION[user] at php.net:**\n\n$comment"));


	$opts = array(
		'method'        => 'POST',
		'content'       => $post,
	);

	return (bool)do_http_request($pull->_links->comments->href, $opts);
}

function ghchangestate($pull, $state)
{
	$content = json_encode(array("state" => $state));

	$opts = array(
		'method'  => 'PATCH',
		'content' => $content
	);

	return (bool)do_http_request($pull->_links->self->href, $opts);
}

function login()
{
	global $errors;

	$func = DEV ? 'verify_password_DEV' : 'verify_password';
	if ($func($_POST['user'], $_POST['pass'])) {
		$_SESSION['user'] = $_POST['user'];
		die(json_encode(array('success' => true, 'user' => $_POST['user'])));
	} else {
		header('HTTP/1.0 401 Unauthorized');
		$_SESSION['user'] = false;
		die(json_encode(array('success' => false, 'errors' => $errors)));
	}
}

function logout()
{
	session_destroy();
	die(json_encode(array('success' => true)));
}

function loggedin()
{
	$result = array(
		'success' => !empty($_SESSION['user'])
	);
	if (!empty($_SESSION['user'])) {
		$result['user'] = $_SESSION['user'];
	}
	die(json_encode($result));
}

function ghupdate()
{
	global $errors;

	if (empty($_SESSION['user'])) {
		header('HTTP/1.0 401 Unauthorized');
		die(json_encode(array('success' => false, 'errors' => array('Unauthorized'))));
	}

	if (empty($_POST['repo'])) {
		header('HTTP/1.0 400 Bad Request');
		die(json_encode(array('success' => false, 'errors' => array("No repo provided"))));
	}

	if (empty($_POST['id']) || !is_numeric($_POST['id'])) {
		header('HTTP/1.0 400 Bad Request');
		die(json_encode(array('success' => false, 'errors' => array("No or inalid id provided"))));
	}

	if (empty($_POST['comment']) || !($comment = trim($_POST['comment']))) {
		header('HTTP/1.0 400 Bad Request');
		die(json_encode(array('success' => false, 'errors' => array("No comment provided"))));
	}

	if (!empty($_POST['state']) && !in_array($_POST['state'], array('open', 'closed'))) {
		header('HTTP/1.0 400 Bad Request');
		die(json_encode(array('success' => false, 'errors' => array("Invalid state"))));
	}

	$pull_raw = @file_get_contents(GITHUB_BASEURL.'repos/'.GITHUB_ORG.'/'.urlencode($_POST['repo']).'/pulls/'.$_POST['id']);
	$pull = $pull_raw ? json_decode($pull_raw) : false;
	if (!$pull) {
		header('HTTP/1.0 400 Bad Request');
		die(json_encode(array('success' => false, 'errors' => array("Failed to get data from GitHub"))));
	}

	$comment = @get_magic_quotes_gpc() ? stripslashes($_POST['comment']) : $_POST['comment'];

	if (!ghpostcomment($pull, $comment)) {
		header('500 Internal Server Error');
		$errors[] = "Failed to add comment on GitHub";
		die(json_encode(array('success' => false, 'errors' => $errors)));
	}

	if (!empty($_POST['state'])) {
		if (!ghchangestate($pull, $_POST['state'])) {
			header('500 Internal Server Error');
			$errors[] = "Failed to set new state";
			die(json_encode(array('success' => false, 'errors' => $errors)));
		}
	}

	die(json_encode(array('success' => true)));
}

function requestlog() {
	if (!isset($_SESSION['debug']['requests'])) {
		$_SESSION['debug']['requests'] = array();
	}

	header('Content-Type: text/plain');
	var_dump($_SESSION['debug']['requests']);
	exit;
}

header('Content-Type: application/json');
session_start();

$accepted_actions = array(
	'login',
	'logout',
	'loggedin',
	'ghupdate',
	'requestlog'
);
if (isset($_REQUEST['action']) && in_array($_REQUEST['action'], $accepted_actions)) {
	$action = $_REQUEST['action'];
	$action();
} else {
	header('HTTP/1.0 400 Bad Request');
	die(json_encode(array('success' => false, 'errors' => array("Unknown method"))));
}

