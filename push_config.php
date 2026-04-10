<?php

// Configuration file for push.php

$config = array(
	// These are the settings for development mode
	'development' => array(

		// NEW The APNS server that we will use
		'url' => 'https://api.sandbox.push.apple.com/3/device/',
		'authKey' => 'AuthKey_xxxxxxxxxx.p8', // your .p8 file PROD or DEV, both work with the same JWT and APNs endpoint
		'keyId' => 'xxxxxxxxxx', // your Key ID from Apple Developer account
		'teamId' => 'xxxxxxxxxx', // your Team ID from Apple Developer account
		'bundleId' => 'com.xxxxx.WhereRU',
		// OLD The APNS server that we will use
		'server' => 'gateway.push.apple.com:2195',

		// OLD The SSL certificate that allows us to connect to the APNS servers
		'certificate' => 'ck.pem',
		'passphrase' => 'whereru',

		// Configuration of the MySQL database
		// for Bluehost use
			// 'host'     => 'localhost',
			// 'dbname'   => 'altcoinf_pushwhereru',
			// 'username' => 'altcoinf_pushwru',
			// 'password' => 'd]682\#%yI1nb3',
		'db' => array(
			'host'     => 'localhost',
			'dbname'   => 'pushchat',
			'username' => 'root',
			'password' => 'xxxxxxxxx',
			),

		// Name and path of our log file
		'logfile' => 'push_development.log',
		),

	// These are the settings for production mode
	'production' => array(

		// NEW The APNS server that we will use
		'url' => 'https://api.push.apple.com/3/device/',
		'authKey' => 'AuthKey_xxxxxxxxxx.p8', // your .p8 file PROD or DEV, both work with the same JWT and APNs endpoint
		'keyId' => 'xxxxxxxxxx', // your Key ID from Apple Developer account
		'teamId' => 'xxxxxxxxxx', // your Team ID from Apple Developer account
		'bundleId' => 'com.xxxxx.WhereRU',

		// OLD The APNS server that we will use
		'server' => 'gateway.push.apple.com:2195',
		// OLD The SSL certificate that allows us to connect to the APNS servers
		'certificate' => 'ck.pem',
		'passphrase' => 'whereru',

		// Configuration of the MySQL database
		'db' => array(
			'host'     => 'localhost',
			'dbname'   => 'pushchat',
			'username' => 'root',
			'password' => 'xxxxxxxxx',
			),

		// Name and path of our log file
		'logfile' => '../log/push_production.log',
		),
	);
