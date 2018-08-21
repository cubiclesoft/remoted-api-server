<?php
	// Remoted API server main service.
	// (C) 2018 CubicleSoft.  All Rights Reserved.

	if (!isset($_SERVER["argc"]) || !$_SERVER["argc"])
	{
		echo "This file is intended to be run from the command-line.";

		exit();
	}

	// Temporary root.
	$rootpath = str_replace("\\", "/", dirname(__FILE__));

	require_once $rootpath . "/support/cli.php";
	require_once $rootpath . "/support/ras_functions.php";

	// Load configuration.
	$config = RAS_LoadConfig();

	if ($argc > 1)
	{
		// Service Manager PHP SDK.
		require_once $rootpath . "/support/servicemanager.php";

		$sm = new ServiceManager($rootpath . "/servicemanager");

		echo "Service manager:  " . $sm->GetServiceManagerRealpath() . "\n\n";

		$servicename = preg_replace('/[^a-z0-9]/', "-", $config["servicename"]);

		if ($argv[1] == "install")
		{
			// Install the service.
			$args = array();
			$options = array(
				"nixuser" => $config["serviceuser"],
				"nixgroup" => $config["serviceuser"]
			);

			$result = $sm->Install($servicename, __FILE__, $args, $options, true);
			if (!$result["success"])  CLI::DisplayError("Unable to install the '" . $servicename . "' service.", $result);
		}
		else if ($argv[1] == "start")
		{
			// Start the service.
			$result = $sm->Start($servicename, true);
			if (!$result["success"])  CLI::DisplayError("Unable to start the '" . $servicename . "' service.", $result);
		}
		else if ($argv[1] == "stop")
		{
			// Stop the service.
			$result = $sm->Stop($servicename, true);
			if (!$result["success"])  CLI::DisplayError("Unable to stop the '" . $servicename . "' service.", $result);
		}
		else if ($argv[1] == "uninstall")
		{
			// Uninstall the service.
			$result = $sm->Uninstall($servicename, true);
			if (!$result["success"])  CLI::DisplayError("Unable to uninstall the '" . $servicename . "' service.", $result);
		}
		else if ($argv[1] == "dumpconfig")
		{
			$result = $sm->GetConfig($servicename);
			if (!$result["success"])  CLI::DisplayError("Unable to retrieve the configuration for the '" . $servicename . "' service.", $result);

			echo "Service configuration:  " . $result["filename"] . "\n\n";

			echo "Current service configuration:\n\n";
			foreach ($result["options"] as $key => $val)  echo "  " . $key . " = " . $val . "\n";
		}
		else
		{
			echo "Command not recognized.  Run the service manager directly for anything other than 'install', 'start', 'stop', 'uninstall', and 'dumpconfig'.\n";
		}

		exit();
	}

	// Start the main server.
	require_once $rootpath . "/support/web_server.php";
	require_once $rootpath . "/support/websocket_server.php";
	require_once $rootpath . "/support/webroute_server.php";
	require_once $rootpath . "/support/random.php";
	require_once $rootpath . "/support/str_basics.php";

	$webserver = new WebServer();
	$wsserver = new WebSocketServer();
	$wrserver = new WebRouteServer();

	$pathmap = array();

	echo "Starting server...\n";
	$result = $webserver->Start($config["host"], $config["port"], false);
	if (!$result["success"])
	{
		var_dump($result);
		exit();
	}

	echo "Ready.\n";

	$stopfilename = __FILE__ . ".notify.stop";
	$reloadfilename = __FILE__ . ".notify.reload";
	$lastservicecheck = time();
	$running = true;

	do
	{
		// Implement the stream_select() call directly since multiple server instances are involved.
		$timeout = 3;
		$readfps = array();
		$writefps = array();
		$exceptfps = NULL;

		$webserver->UpdateStreamsAndTimeout("", $timeout, $readfps, $writefps);
		$wsserver->UpdateStreamsAndTimeout("", $timeout, $readfps, $writefps);
		$wrserver->UpdateStreamsAndTimeout("", $timeout, $readfps, $writefps);

		$result = @stream_select($readfps, $writefps, $exceptfps, $timeout);
		if ($result === false)  break;

		// Web server.
		$result = $webserver->Wait(0);

		// Handle active clients.
		foreach ($result["clients"] as $id => $client)
		{
			if ($client->appdata === false)
			{
				echo "Webserver client ID " . $id . " connected.\n";

				$client->appdata = array("mode" => false, "path" => false);
			}

			// Check for a valid API key.
			if ($client->appdata["mode"] === false && isset($client->headers["X-Remoted-Apikey"]))
			{
				$apikey = $client->headers["X-Remoted-Apikey"];

				if (Str::CTstrcmp($config["client_apikey"], $apikey) == 0)  $client->appdata["mode"] = "client";
				else if (Str::CTstrcmp($config["server_apikey"], $apikey) == 0)  $client->appdata["mode"] = "server";

				if ($client->appdata["mode"] !== false)  echo "Valid " . $client->appdata["mode"] . " API key used.\n";
			}

			if ($client->appdata["mode"] !== false && $client->appdata["path"] === false)
			{
				$url = HTTP::ExtractURL($client->url);
				$client->appdata["path"] = $url["path"];
			}

			// Wait until the request is complete before fully processing inputs.
			if ($client->requestcomplete)
			{
				// Prevent proxies from doing bad things.
				$client->SetResponseNoCache();

				if ($client->appdata["mode"] === false)
				{
					echo "Missing API key.\n";

					$client->SetResponseCode(403);
					$client->SetResponseContentType("application/json");
					$client->AddResponseContent(json_encode(array("success" => false, "error" => "Invalid or missing 'X-Remoted-APIKey' header.", "errorcode" => "invalid_missing_apikey")));
					$client->FinalizeResponse();
				}
				else if ($client->appdata["path"] === false)
				{
					echo "Unknown or invalid path.\n";

					$client->SetResponseCode(403);
					$client->SetResponseContentType("application/json");
					$client->AddResponseContent(json_encode(array("success" => false, "error" => "Unknown or invalid path.  Bad request.", "errorcode" => "invalid_request_path")));
					$client->FinalizeResponse();
				}
				else if ($client->appdata["mode"] === "server")
				{
					// Handle WebRoute upgrade requests.
					$id2 = $wrserver->ProcessWebServerClientUpgrade($webserver, $client, true);
					if ($id2 !== false)
					{
						$client2 = $wrserver->GetClient($id2);

						// Clean up the waiting queue.
						if ($client2->linkid !== false)
						{
							$id3 = $pathmap[$client2->appdata["path"]];
							$client3 = $wsserver->GetClient($id3);

							unset($client3->appdata["waiting"][$client2->linkid]);
						}

						echo "Webserver client ID " . $id . " upgraded to WebRoute.  WebRoute client ID is " . $id2 . ".\n";
					}
					else
					{
						// Handle WebSocket upgrade requests.
						if (!isset($pathmap[$client->appdata["path"]]))  $id2 = $wsserver->ProcessWebServerClientUpgrade($webserver, $client);
						if ($id2 !== false)
						{
							$client2 = $wsserver->GetClient($id2);

							$client2->appdata["waiting"] = array();

							$pathmap[$client2->appdata["path"]] = $id2;

							echo "Webserver client ID " . $id . " upgraded to WebSocket.  WebSocket client ID is " . $id2 . ".  Listening on '" . $client2->appdata["path"] . "'.\n";
						}
						else
						{
							// Reject all other requests.
							$result2 = array("success" => false, "error" => "Invalid request.  Expected WebSocket or WebRoute upgrade.", "errorcode" => "invalid_request");

							$client->SetResponseCode(400);

							// Send the response.
							$client->SetResponseContentType("application/json");
							$client->AddResponseContent(json_encode($result2));
							$client->FinalizeResponse();

							$client->appdata["path"] = false;
						}
					}
				}
				else
				{
					// Handle WebRoute upgrade requests.
					$ipaddr = $client->ipaddr;
					$id2 = (isset($pathmap[$client->appdata["path"]]) ? $wrserver->ProcessWebServerClientUpgrade($webserver, $client) : false);
					if ($id2 !== false)
					{
						$client2 = $wrserver->GetClient($id2);

						echo "Webserver client ID " . $id . " upgraded to WebRoute.  WebRoute client ID is " . $id2 . ".\n";

						// Notify the appropriate WebSocket server.
						$id3 = $pathmap[$client2->appdata["path"]];
						$client3 = $wsserver->GetClient($id3);

						$data = array(
							"ipaddr" => $ipaddr,
							"id" => $client2->webrouteid,
							"timeout" => (isset($client2->headers["Webroute-Timeout"]) && is_numeric($client2->headers["Webroute-Timeout"]) && (int)$client2->headers["Webroute-Timeout"] > $client2->timeout ? (int)$client2->headers["Webroute-Timeout"] : $client2->timeout)
						);

						$client3->websocket->Write(json_encode($data, JSON_UNESCAPED_SLASHES), WebSocket::FRAMETYPE_TEXT);

						$client3->appdata["waiting"][$id2] = true;
					}
					else
					{
						if (!isset($pathmap[$client->appdata["path"]]))
						{
							$result2 = array("success" => false, "error" => "Requested destination does not exist at this time.", "errorcode" => "missing_destination");

							$client->SetResponseCode(502);
						}
						else
						{
							$result2 = array("success" => false, "error" => "Invalid request.  Expected WebRoute upgrade.", "errorcode" => "invalid_request");

							$client->SetResponseCode(400);
						}

						// Send the response.
						$client->SetResponseContentType("application/json");
						$client->AddResponseContent(json_encode($result2));
						$client->FinalizeResponse();

						$client->appdata["path"] = false;
					}
				}
			}
		}

		// Handle removed clients.
		foreach ($result["removed"] as $id => $result2)
		{
			if ($result2["client"]->appdata !== false)
			{
				echo "Web server client ID " . $id . " disconnected.\n";

//				echo "Client ID " . $id . " disconnected.  Reason:\n";
//				var_dump($result2["result"]);
//				echo "\n";
			}
		}

		// WebSocket server.
		$result = $wsserver->Wait(0);

		// Handle active clients.
		foreach ($result["clients"] as $id => $client)
		{
			// Ignore all input packets.
			$ws = $client->websocket;

			$result2 = $ws->Read();
			while ($result2["success"] && $result2["data"] !== false)
			{
				$result2 = $ws->Read();
			}
		}

		foreach ($result["removed"] as $id => $result2)
		{
			$client = $result2["client"];

			if ($client->appdata !== false)
			{
				echo "WebSocket client ID " . $id . " disconnected.\n";

				// Remove the path.
				unset($pathmap[$client->appdata["path"]]);

				// Disconnect waiting WebRoute clients so they don't timeout.
				foreach ($client->appdata["waiting"] as $id2 => $val)
				{
					$wrserver->RemoveClient($id2);
				}

//				echo "WebSocket client ID " . $id . " disconnected.  Reason:\n";
//				var_dump($result2["result"]);
//				echo "\n";
			}
		}

		// WebRoute server.
		$result = $wrserver->Wait(0);

		foreach ($result["removed"] as $id => $result2)
		{
			$client = $result2["client"];

			if ($client->appdata !== false)
			{
				echo "WebRoute client ID " . $id . " disconnected.\n";

				// Remove from the associated WebSocket waiting queue.
				if (isset($pathmap[$client->appdata["path"]]))
				{
					$id2 = $pathmap[$client->appdata["path"]];
					$client2 = $wsserver->GetClient($id2);

					unset($client2->appdata["waiting"][$id]);
				}

//				echo "WebRoute client ID " . $id . " disconnected.  Reason:\n";
//				var_dump($result2["result"]);
//				echo "\n";
			}
		}

		// Check the status of the two service file options for correct Service Manager integration.
		if ($lastservicecheck <= time() - 3)
		{
			if (file_exists($stopfilename))
			{
				// Initialize termination.
				echo "Stop requested.\n";

				$running = false;
			}
			else if (file_exists($reloadfilename))
			{
				// Reload configuration and then remove reload file.
				echo "Reload config requested.  Exiting.\n";

				$running = false;
			}

			$lastservicecheck = time();
		}
	} while ($running);
?>