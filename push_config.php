<?php

// Configuration file for push.php

$config = array(
	// These are the settings for development mode
	'development' => array(

		// The APNS server that we will use
		'server' => 'gateway.push.apple.com:2195',

		// The SSL certificate that allows us to connect to the APNS servers
		'certificate' => 'ck.pem',
		'passphrase' => 'whereru',

		// Configuration of the MySQL database
		// for local testing use
			// 'host'     => 'localhost',
			// 'dbname'   => 'pushchat',
			// 'username' => 'pushchat',
			// 'password' => 'd]682\#%yI1nb3',
		// for Bluehost use
			// 'host'     => 'localhost',
			// 'dbname'   => 'altcoinf_pushwhereru',
			// 'username' => 'altcoinf_pushwru',
			// 'password' => 'd]682\#%yI1nb3',
		'db' => array(
			'host'     => 'localhost',
			'dbname'   => 'altcoinf_pushwhereruprod',
			'username' => 'altcoinf_pushw',
			'password' => 'd]682\#%yI1nb3',
			),

		// Name and path of our log file
		'logfile' => 'push_development.log',
		),

	// These are the settings for production mode
	'production' => array(

		// The APNS server that we will use
		'server' => 'gateway.push.apple.com:2195',

		// The SSL certificate that allows us to connect to the APNS servers
		'certificate' => 'ck.pem',
		'passphrase' => 'whereru',

		// Configuration of the MySQL database
		'db' => array(
			'host'     => 'localhost',
			'dbname'   => 'altcoinf_pushwhereruprod',
			'username' => 'altcoinf_pushw',
			'password' => 'd]682\#%yI1nb3',
			),

		// Name and path of our log file
		'logfile' => '../log/push_production.log',
		),
	);
