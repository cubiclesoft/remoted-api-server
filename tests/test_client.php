<?php
	// Remoted API SDK class test.  The test server and Remoted API Server must be running first.
	// (C) 2017 CubicleSoft.  All Rights Reserved.

	if (!isset($_SERVER["argc"]) || !$_SERVER["argc"])
	{
		echo "This file is intended to be run from the command-line.";

		exit();
	}

	// Temporary root.
	$rootpath = str_replace("\\", "/", dirname(__FILE__));

	require_once $rootpath . "/../sdks/php/sdk_remotedapi.php";

	// Load configuration.  Normally a remoted API client wouldn't do this.
	// Instead, a url/host line would be stored in a config file that RemotedAPI::IsRemoted() detects.
	$config = json_decode(file_get_contents($rootpath . "/../config.dat"), true);

	// Normally this would be a host line in a configuration file.  It's dynamically calculated here for the tests to work.
	// Multiple URLs are separated by at least once space.
	$url = "rwr://" . $config["client_apikey"] . "@" . $config["host"] . ":" . $config["port"] . "/test/path/   https://localhost:1234";
	$fp = false;

	if ($fp === false && RemotedAPI::IsRemoted($url))
	{
		$result = RemotedAPI::Connect($url);
		if (!$result["success"])
		{
			var_dump($result);
			exit();
		}

		$fp = $result["fp"];
		$url = $result["url"];
	}
	else
	{
		$url = RemotedAPI::ExtractRealHost($url);
	}

	$web = new WebBrowser();

	$options = array(
		"fp" => $fp
	);

	$result = $web->Process($url . "/v1/some/api", $options);
	if (!$result["success"] || $result["response"]["code"] != 200)
	{
		var_dump($result);
		exit();
	}

	var_dump($result["body"]);
?>