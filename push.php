<?php

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
	private $fp = NULL;
	private $server;
	private $certificate;
	private $passphrase;

	function __construct($config)
	{
		$this->server = $config['server'];
		$this->certificate = $config['certificate'];
		$this->passphrase = $config['passphrase'];

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
		writeToLog('Connecting to ' . $this->server);

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
		$ctx = stream_context_create();
		stream_context_set_option($ctx, 'ssl', 'local_cert', $this->certificate);
		stream_context_set_option($ctx, 'ssl', 'cafile', 'entrust_2048_ca.cer');
		stream_context_set_option($ctx, 'ssl', 'passphrase', $this->passphrase);

		$this->fp = stream_socket_client(
			'ssl://' . $this->server, $err, $errstr, 60,
			STREAM_CLIENT_CONNECT|STREAM_CLIENT_PERSISTENT, $ctx);

		if (!$this->fp)
		{
			writeToLog("Failed to connect: $err $errstr");
			return FALSE;
		}

		writeToLog('Connection OK');
		return TRUE;
	}

	// Drops the connection to the APNS server.
	function disconnectFromAPNS()
	{
		fclose($this->fp);
		$this->fp = NULL;
	}

	// Attempts to reconnect to Apple's Push Notification Service. Exits with
	// an error if the connection cannot be re-established after 3 attempts.
	function reconnectToAPNS()
	{
		$this->disconnectFromAPNS();
	
		$attempt = 1;
	
		while (true)
		{
			writeToLog('Reconnecting to ' . $this->server . ", attempt $attempt");

			if ($this->connectToAPNS())
				return;

			if ($attempt++ > 3)
				fatalError('Could not reconnect after 3 attempts');

			sleep(60);
		}
	}

	// Sends a notification to the APNS server. Returns FALSE if the connection
	// appears to be broken, TRUE otherwise.
	function sendNotification($messageId, $deviceToken, $payload)
	{
		if (strlen($deviceToken) != 64)
		{
			writeToLog("Message $messageId is probably an Android device - deal with it differently now");
			$jsonString = $payload;
			$obj = json_decode($jsonString);
//			echo $obj->alert;
			if (strcmp($obj->aps->extra, 'whereru') == 0) {
				$data = array( 'extra' => 'x', 'payload' => $payload, 'message' => 'Where R U', 'asker' => 'x', 'loc' => 'x' );
			} else {
				$data = array( 'extra' => 'y', 'payload' => $payload, 'message' => $obj->aps->alert, 'asker' => 'y', 'loc' => 'y' );
			}
			// $data = array( 'payload' => $payload, 'message' => 'WhereRU New Message');
			// $ids = array( 'eUvHaYUHq1M:APA91bElViVWfJvhOE3qs5g9TX83ViI8nCct00dx8-Q-QhJTgU1aZSsq4zotAiEW425LLubdYkgzN9lfxr6Eacrd96z2oAZVTlgSJXP4AcALPuW06m_ps9ohB2EMTDUPviIsVCBg_e5z');
			$ids = array($deviceToken);

			$this->sendGoogleCloudMessage( $data, $ids);
			writeToLog("did sendGoogleCloudMessage work");
			return TRUE;
		}

		if (strlen($payload) < 10)
		{
			writeToLog("Message $messageId has invalid payload");
			return TRUE;
		}

		writeToLog("Sending message $messageId to '$deviceToken', payload: '$payload'");

		if (!$this->fp)
		{
			writeToLog('No connection to APNS');
			return FALSE;
		}

		// The simple format
		$msg = chr(0)                       // command (1 byte)
		     . pack('n', 32)                // token length (2 bytes)
		     . pack('H*', $deviceToken)     // device token (32 bytes)
		     . pack('n', strlen($payload))  // payload length (2 bytes)
		     . $payload;                    // the JSON payload

		/*
		// The enhanced notification format
		$msg = chr(1)                       // command (1 byte)
		     . pack('N', $messageId)        // identifier (4 bytes)
		     . pack('N', time() + 86400)    // expire after 1 day (4 bytes)
		     . pack('n', 32)                // token length (2 bytes)
		     . pack('H*', $deviceToken)     // device token (32 bytes)
		     . pack('n', strlen($payload))  // payload length (2 bytes)
		     . $payload;                    // the JSON payload
		*/

		$result = @fwrite($this->fp, $msg, strlen($msg));

		if (!$result)
		{
			writeToLog('Message not delivered');
			return FALSE;
		}

		writeToLog('Message successfully delivered');
		return TRUE;
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
