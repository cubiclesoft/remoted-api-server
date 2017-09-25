<?php
	// RemotedAPIWebServer class test.  Remoted API Server must be running first.
	// (C) 2017 CubicleSoft.  All Rights Reserved.

	if (!isset($_SERVER["argc"]) || !$_SERVER["argc"])
	{
		echo "This file is intended to be run from the command-line.";

		exit();
	}

	// Temporary root.
	$rootpath = str_replace("\\", "/", dirname(__FILE__));

	require_once $rootpath . "/../support/remotedapi_web_server.php";

	// Load configuration.  Normally a remoted API web server wouldn't do this.
	// Instead, a host line would be stored in a config file that RemotedAPIWebServer::IsRemoted() detects.
	$config = json_decode(file_get_contents($rootpath . "/../config.dat"), true);

	// Normally this would be a host line in a configuration file.  It's dynamically calculated here for the tests to work.
	$host = "rws://" . $config["server_apikey"] . "@" . $config["host"] . ":" . $config["port"] . "/test/path/";

	$webserver = (RemotedAPIWebServer::IsRemoted($host) ? new RemotedAPIWebServer() : new WebServer());

	$webtracker = array();

	echo "Starting server...\n";
	$result = $webserver->Start($host, 0, false);
	if (!$result["success"])
	{
		var_dump($result);
		exit();
	}

	echo "Ready.\n";

	do
	{
		// Implement the stream_select() call directly when using multiple server instances.
		$timeout = 3;
		$readfps = array();
		$writefps = array();
		$exceptfps = NULL;

		$webserver->UpdateStreamsAndTimeout("", $timeout, $readfps, $writefps);

		$result = @stream_select($readfps, $writefps, $exceptfps, $timeout);
		if ($result === false)
		{
			if ($webserver instanceof RemotedAPIWebServer)
			{
				sleep(5);

				continue;
			}
			else
			{
				break;
			}
		}

		// Web server.
		$result = $webserver->Wait(0);

		// Handle active clients.
		foreach ($result["clients"] as $id => $client)
		{
			if (!isset($webtracker[$id]))
			{
				echo "Webserver client ID " . $id . " connected.\n";

				$webtracker[$id] = array();
			}

			if ($client->requestcomplete)
			{
				$result2 = array("success" => true, "message" => "It works!");

				// Prevent proxies from doing bad things.
				$client->SetResponseNoCache();

				$client->SetResponseCode(200);

				// Send the response.
				$client->SetResponseContentType("application/json");
				$client->AddResponseContent(json_encode($result2));
				$client->FinalizeResponse();

				echo "Sending client " . $id . ":  'It works!'\n";
			}
		}

		// Handle removed clients.
		foreach ($result["removed"] as $id => $result2)
		{
			if (isset($webtracker[$id]))
			{
				echo "Web server client ID " . $id . " disconnected.\n";

//				echo "Client ID " . $id . " disconnected.  Reason:\n";
//				var_dump($result2["result"]);
//				echo "\n";

				unset($webtracker[$id]);
			}
		}
	} while (1);
?>