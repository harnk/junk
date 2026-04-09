<?php

// WARNING 
// MAR 2026 -- This whole thing is obsolete and must be replaced with a new push notification system that can handle both iOS and Android devices. The current system is based on an old tutorial and only supports iOS devices. It also uses the old binary interface to APNS, which is no longer recommended by Apple. We need to switch to the new HTTP/2 API for APNS and also implement support for Firebase Cloud Messaging (FCM) for Android devices. This will require a significant rewrite of the code, but it will be worth it in the long run to have a more modern and robust push notification system.


// This script should be run as a background process on the server. It checks
// every few seconds for new messages in the database table push_queue and 
// sends them to the Apple Push Notification Service.
//
// Usage: php push.php development &
//    or: php push.php production &
//
// The & will detach the script from the shell and run it in the background.
//
// The "development" or "production" parameter determines which APNS server
// the script will connect to. You can configure this in "push_config.php".
// Note: In development mode, the app should be compiled with the development
// provisioning profile and it should have a development-mode device token.
//
// If a fatal error occurs (cannot establish a connection to the database or
// APNS), this script exits. You should probably have some type of watchdog
// that restarts the script or at least notifies you when it quits. If this
// script isn't running, no push notifications will be delivered!

try
{
	require_once('push_config.php');

	ini_set('display_errors', 'off');

//	if ($argc != 2 || ($argv[1] != 'development' && $argv[1] != 'production'))
//		exit("Usage: php push.php development|production -- error args:[0]".$argv[0].", [1]" .$argv[1].", [2]" .$argv[2]."" . PHP_EOL);

//	$mode = $argv[1];
	$mode = 'development';
	echo "mode is development";
	$config = $config[$mode];

	writeToLog("Push script started ($mode mode)");

	$obj = new APNS_Push($config);
	$obj->start();
}
catch (Exception $e)
{
	fatalError($e);
}

////////////////////////////////////////////////////////////////////////////////

function writeToLog($message)
{
	global $config;
	if ($fp = fopen($config['logfile'], 'at'))
	{
		fwrite($fp, date('c') . ' ' . $message . PHP_EOL);
		fclose($fp);
	}
}

function fatalError($message)
{
	writeToLog('Exiting with fatal error: ' . $message);
	exit;
}

////////////////////////////////////////////////////////////////////////////////

class APNS_Push
{
	private $url;
	private $authKey;
	private $keyId;
	private $teamId;
	private $bundleId;
	private $jwt;
	private $jwtIssuedAt = 0;

	function __construct($config)
	{
		// APNS HTTP/2 settings read from the configuration file.
		$this->url = rtrim($config['url'], '/');
		$this->authKey = $config['authKey'];
		$this->keyId = $config['keyId'];
		$this->teamId = $config['teamId'];
		$this->bundleId = $config['bundleId'];

		if (!file_exists($this->authKey))
			exit('APNS auth key not found: ' . $this->authKey . PHP_EOL);

		// Create JWT for APNS authentication
		$header = ['alg' => 'ES256', 'kid' => $this->keyId];
		$claims = ['iss' => $this->teamId, 'iat' => time()];

		function base64url($data) {
		    return rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');
		}

		$this->jwt = base64url($header) . '.' . base64url($claims);

		openssl_sign($this->jwt, $signature, file_get_contents($this->authKey), 'sha256');
		$this->jwt .= '.' . rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

		// Create a connection to the database.
		$this->pdo = new PDO(
			'mysql:host=' . $config['db']['host'] . ';dbname=' . $config['db']['dbname'], 
			$config['db']['username'], 
			$config['db']['password'],
			array());

		// If there is an error executing database queries, we want PDO to
		// throw an exception.
		$this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		// We want the database to handle all strings as UTF-8.
		$this->pdo->query('SET NAMES utf8');
	}

	// This is the main loop for this script. It polls the database for new
	// messages, sends them to APNS, sleeps for a few seconds, and repeats this
	// forever (or until a fatal error occurs and the script exits).
	function start()
	{
		writeToLog('Initializing APNS HTTP/2 client');

		if (!$this->connectToAPNS())
			exit;

		while (true)
		{
			// Do at most 20 messages at a time. Note: we send each message in
			// a separate packet to APNS. It would be more efficient if we 
			// combined several messages into one packet, but this script isn't
			// smart enough to do that. ;-)

			$stmt = $this->pdo->prepare('SELECT * FROM push_queue WHERE time_sent IS NULL LIMIT 20');
			$stmt->execute();
			$messages = $stmt->fetchAll(PDO::FETCH_OBJ);

			foreach ($messages as $message)
			{
				if ($this->sendNotification($message->message_id, $message->device_token, $message->payload))
				{
					$stmt = $this->pdo->prepare('UPDATE push_queue SET time_sent = NOW() WHERE message_id = ?');
					$stmt->execute(array($message->message_id));
				}
				else  // failed to deliver
				{
					$this->reconnectToAPNS();
				}
			}

			unset($messages);			
			sleep(2);
		}
	}

	// Opens an SSL/TLS connection to Apple's Push Notification Service (APNS).
	// Returns TRUE on success, FALSE on failure.
	function connectToAPNS()
	{
		if (!function_exists('curl_init')) {
			writeToLog('cURL extension is required for APNS HTTP/2');
			return FALSE;
		}

		$curlVersion = curl_version();
		if (defined('CURL_VERSION_HTTP2') && !($curlVersion['features'] & CURL_VERSION_HTTP2)) {
			writeToLog('cURL must be built with HTTP/2 support for APNS');
			return FALSE;
		}

		try {
			$this->getApnsJwt();
		} catch (Exception $e) {
			writeToLog('APNS JWT generation failed: ' . $e->getMessage());
			return FALSE;
		}

		writeToLog('APNS HTTP/2 initialized');
		return TRUE;
	}

	// Drops the connection to the APNS server.
	function disconnectFromAPNS()
	{
		// No persistent APNS connection is maintained in HTTP/2 mode.
	}

	// Attempts to reconnect to Apple's Push Notification Service. Exits with
	// an error if the connection cannot be re-established after 3 attempts.
	function reconnectToAPNS()
	{
		$attempt = 1;

		while (true)
		{
			writeToLog('Reinitializing APNS HTTP/2 client, attempt ' . $attempt);

			if ($this->connectToAPNS())
				return;

			if ($attempt++ > 3)
				fatalError('Could not reinitialize APNS HTTP/2 client after 3 attempts');

			sleep(60);
		}
	}

	private function base64UrlEncode($data)
	{
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}

	private function getApnsJwt()
	{
		if ($this->jwt && (time() - $this->jwtIssuedAt) < 3000)
			return $this->jwt;

		$header = ['alg' => 'ES256', 'kid' => $this->keyId];
		$claims = ['iss' => $this->teamId, 'iat' => time()];

		$data = $this->base64UrlEncode(json_encode($header)) . '.' . $this->base64UrlEncode(json_encode($claims));

		$privateKey = file_get_contents($this->authKey);
		$pkResource = openssl_pkey_get_private($privateKey);
		if (!$pkResource)
			throw new Exception('Unable to load APNS auth key');

		if (!openssl_sign($data, $signature, $pkResource, OPENSSL_ALGO_SHA256))
			throw new Exception('Unable to sign APNS JWT');

		$this->jwt = $data . '.' . $this->base64UrlEncode($signature);
		$this->jwtIssuedAt = time();

		return $this->jwt;
	}

	private function normalizeApnsPayload($messageId, $payload)
	{
		$json = json_decode($payload, true);
		if (!is_array($json))
		{
			writeToLog("Message $messageId has invalid payload");
			return false;
		}

		if (!isset($json['aps']))
		{
			if (isset($json['alert']) || isset($json['sound']) || isset($json['badge']))
				$json = ['aps' => $json];
			else
				$json = ['aps' => ['alert' => $json]];
		}

		return json_encode($json, JSON_UNESCAPED_UNICODE);
	}

	private function sendApnsRequest($messageId, $deviceToken, $payloadJson)
	{
		$jwt = $this->getApnsJwt();
		$url = $this->url . '/' . $deviceToken;

		$headers = [
			'apns-topic: ' . $this->bundleId,
			'apns-push-type: alert',
			'apns-priority: 10',
			'authorization: bearer ' . $jwt,
			'content-type: application/json',
		];

		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadJson);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
		curl_setopt($ch, CURLOPT_TIMEOUT, 60);

		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlErr = curl_errno($ch) ? curl_error($ch) : null;

		if ($curlErr)
		{
			writeToLog("APNS curl error for message $messageId: $curlErr");
			return false;
		}

		if ($httpCode === 200)
		{
			writeToLog("Message $messageId successfully delivered to APNS");
			return true;
		}

		writeToLog("APNS error for message $messageId: HTTP $httpCode response: $response");
		return false;
	}

	// Sends a notification to the APNS server. Returns FALSE if the connection
	// appears to be broken, TRUE otherwise.
	function sendNotification($messageId, $deviceToken, $payload)
	{
		if (strlen($deviceToken) != 64)
		{
			$jsonString = $payload;
			$obj = json_decode($jsonString);
			// write to push_development.log in same folder as this php file
			writeToLog("Message $messageId is for an Android device - $obj->extra");
			//			echo $obj->alert;
			if (strcmp($obj->extra, "whereru") == 0) {
				$data = array( 'payload' => $payload, 'message' => 'wru' );
				writeToLog("sending message wru and payload - $payload");
			} else {
				$data = array( 'payload' => $payload, 'message' => $obj->aps->alert );
				writeToLog("sending message $obj->alert and payload - $payload");
			}
			// $data = array( 'payload' => $payload, 'message' => 'WhereRU New Message');
			// $ids = array( 'eUvHaYUHq1M:APA91bElViVWfJvhOE3qs5g9TX83ViI8nCct00dx8-Q-QhJTgU1aZSsq4zotAiEW425LLubdYkgzN9lfxr6Eacrd96z2oAZVTlgSJXP4AcALPuW06m_ps9ohB2EMTDUPviIsVCBg_e5z');
			$ids = array($deviceToken);

			$this->sendGoogleCloudMessage( $data, $ids);
			writeToLog("did sendGoogleCloudMessage work");
			return TRUE;
		}

		$payloadJson = $this->normalizeApnsPayload($messageId, $payload);
		if ($payloadJson === false)
			return TRUE;

		writeToLog("Sending message $messageId to APNS device token: $deviceToken");
		return $this->sendApnsRequest($messageId, $deviceToken, $payloadJson);
	}

		function sendGoogleCloudMessage( $data, $ids )
	{
		writeToLog("sending ANDROID push now");
	    // Insert real GCM API key from Google APIs Console    // https://code.google.com/apis/console/        
	    $apiKey = 'AIzaSyCltAtOcOSL5laJ8iQ5RVqNDD1v7HeFTh0';
	    // Define URL to GCM endpoint
	    $url = 'https://gcm-http.googleapis.com/gcm/send';
	    // Set GCM post variables (device IDs and push payload)     
	    $post = array(
	                    'registration_ids'  => $ids,
	                    'data'              => $data,
	                    );
	    // Set CURL request headers (authentication and type)       
	    $headers = array( 
	                        'Authorization: key=' . $apiKey,
	                        'Content-Type: application/json'
	                    );
	    // Initialize curl handle       
	    $ch = curl_init();
	    // Set URL to GCM endpoint      
	    curl_setopt( $ch, CURLOPT_URL, $url );
	    // Set request method to POST       
	    curl_setopt( $ch, CURLOPT_POST, true );
	    // Set our custom headers       
	    curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
	    // Get the response back as string instead of printing it       
	    curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	    // Set JSON post data
	    curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $post ) );
	    echo '==================' . "\r\n";
	    echo json_encode( $post ) . "\r\n";
	    echo '==================' . "\r\n";
	    // Actually send the push   
	    $result = curl_exec( $ch );
	    // Error handling
	    if ( curl_errno( $ch ) ) {
	        echo 'GCM error: ' . curl_error( $ch );
	    }
	    // Close curl handle
	    curl_close( $ch );
	    // Debug GCM response       
	    echo $result;
	}



}
