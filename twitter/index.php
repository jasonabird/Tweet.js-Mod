<?php
/*

Stan Scates
blr | further

stan@sc8s.com
blrfurther.com

Basic OAuth and caching layer for Seaofclouds' tweet.js, designed
to introduce compatibility with Twitter's v1.1 API.

Version: 1.4
Created: 2013.02.20

https://github.com/seaofclouds/tweet
https://github.com/themattharris/tmhOAuth

 */

header('content-type: application/json; charset=utf-8');
$allowedServers = array(
    'http://www.example.com'
);

$referrer = $_SERVER['HTTP_ORIGIN'];

if (in_array($referrer, $allowedServers)) {
    $allowString = 'Access-Control-Allow-Origin: '.$referrer;
    header($allowString);
}

if(empty($_POST) && empty($_GET)) { die(); }

class ezTweet {
	/*************************************** config ***************************************/

   // Your Twitter App Consumer Key
	private $consumer_key = 'YOUR_CONSUMER_KEY';

	// Your Twitter App Consumer Secret
	private $consumer_secret = 'YOUR_CONSUMER_SECRET';

	// Your Twitter App Access Token
	private $user_token = 'YOUR_ACCESS_TOKEN';

	// Your Twitter App Access Token Secret
	private $user_secret = 'YOUR_ACCESS_TOKEN_SECRET';

	// Path to tmhOAuth libraries
	private $lib = './lib/';

	// Enable caching
	private $cache_enabled = true;

	// Cache interval (minutes)
	private $cache_interval = 15;

	// Path to writable cache directory
	private $cache_dir = './';

	// Enable debugging
	private $debug = false;

	/**************************************************************************************/

    private $data;

	public function __construct() {
		// Initialize paths and etc.
		$this->pathify($this->cache_dir);
		$this->pathify($this->lib);
		$this->message = '';
        if (!empty($_POST))
            $this->data = $_POST;
        if (!empty($_GET))
            $this->data = $_GET;

		// Set server-side debug params
		if($this->debug === true) {
			error_reporting(-1);
		} else {
			error_reporting(0);
		}
	}

	public function is_valid_callback($subject) {
	    $identifier_syntax = '/^[$_\p{L}][$_\p{L}\p{Mn}\p{Mc}\p{Nd}\p{Pc}\x{200C}\x{200D}]*+$/u';

	    $reserved_words = array('break', 'do', 'instanceof', 'typeof', 'case',
	      'else', 'new', 'var', 'catch', 'finally', 'return', 'void', 'continue',
	      'for', 'switch', 'while', 'debugger', 'function', 'this', 'with',
	      'default', 'if', 'throw', 'delete', 'in', 'try', 'class', 'enum',
	      'extends', 'super', 'const', 'export', 'import', 'implements', 'let',
	      'private', 'public', 'yield', 'interface', 'package', 'protected',
	      'static', 'null', 'true', 'false');

	    return preg_match($identifier_syntax, $subject)
	        && ! in_array(mb_strtolower($subject, 'UTF-8'), $reserved_words);
	}


	public function fetch() {
		$json = json_encode(
			array(
				'response' => json_decode($this->getJSON(), true),
				'message' => ($this->debug) ? $this->message : false
			)
		);

		# JSON if no callback
		if( ! isset($_GET['callback'])) {
			echo $json;
			exit();
		}

		# JSONP if valid callback
		if($this->is_valid_callback($_GET['callback'])) {
			echo $_GET['callback'].'('.$json.')';
			exit();
		}
		echo $json;
	}

	private function getJSON() {
		if($this->cache_enabled === true) {
			$CFID = $this->generateCFID();
			$cache_file = $this->cache_dir.$CFID;

			if(file_exists($cache_file) && (filemtime($cache_file) > (time() - 60 * intval($this->cache_interval)))) {
				return file_get_contents($cache_file, FILE_USE_INCLUDE_PATH);
			} else {

				$JSONraw = $this->getTwitterJSON();
				$JSON = $JSONraw['response'];

				// Don't write a bad cache file if there was a CURL error
				if($JSONraw['errno'] != 0) {
					$this->consoleDebug($JSONraw['error']);
					return $JSON;
				}

				if($this->debug === true) {
					// Check for twitter-side errors
					$pj = json_decode($JSON, true);
					if(isset($pj['errors'])) {
						foreach($pj['errors'] as $error) {
							$message = 'Twitter Error: "'.$error['message'].'", Error Code #'.$error['code'];
							$this->consoleDebug($message);
						}
						return false;
					}
				}

				if(is_writable($this->cache_dir) && $JSONraw) {
					if(file_put_contents($cache_file, $JSON, LOCK_EX) === false) {
						$this->consoleDebug("Error writing cache file");
					}
				} else {
					$this->consoleDebug("Cache directory is not writable");
				}
				return $JSON;
			}
		} else {
			$JSONraw = $this->getTwitterJSON();

			if($this->debug === true) {
				// Check for CURL errors
				if($JSONraw['errno'] != 0) {
					$this->consoleDebug($JSONraw['error']);
				}

				// Check for twitter-side errors
				$pj = json_decode($JSONraw['response'], true);
				if(isset($pj['errors'])) {
					foreach($pj['errors'] as $error) {
						$message = 'Twitter Error: "'.$error['message'].'", Error Code #'.$error['code'];
						$this->consoleDebug($message);
					}
					return false;
				}
			}
			return $JSONraw['response'];
		}
	}

	private function getTwitterJSON() {
		require $this->lib.'tmhOAuth.php';
		require $this->lib.'tmhUtilities.php';

		$tmhOAuth = new tmhOAuth(array(
            'host'                  => $this->data['request']['host'],
			'consumer_key'          => $this->consumer_key,
			'consumer_secret'       => $this->consumer_secret,
			'user_token'            => $this->user_token,
			'user_secret'           => $this->user_secret,
			'curl_ssl_verifypeer'   => false
		));

		$url = $this->data['request']['url'];
		$params = $this->data['request']['parameters'];

		$tmhOAuth->request('GET', $tmhOAuth->url($url), $params);
		return $tmhOAuth->response;
	}

	private function generateCFID() {
		// The unique cached filename ID
		return md5(serialize($this->data)).'.json';
	}

	private function pathify(&$path) {
		// Ensures our user-specified paths are up to snuff
		$path = realpath($path).'/';
	}

	private function consoleDebug($message) {
		if($this->debug === true) {
			$this->message .= 'tweet.js: '.$message."\n";
		}
	}
}

$ezTweet = new ezTweet;
$ezTweet->fetch();