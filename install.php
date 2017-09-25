<?php
	// Remoted API server installer.
	// (C) 2017 CubicleSoft.  All Rights Reserved.

	if (!isset($_SERVER["argc"]) || !$_SERVER["argc"])
	{
		echo "This file is intended to be run from the command-line.";

		exit();
	}

	// Temporary root.
	$rootpath = str_replace("\\", "/", dirname(__FILE__));

	require_once $rootpath . "/support/cli.php";
	require_once $rootpath . "/support/random.php";
	require_once $rootpath . "/support/ras_functions.php";

	// Process the command-line options.
	$options = array(
		"shortmap" => array(
			"s" => "suppressoutput",
			"?" => "help"
		),
		"rules" => array(
			"suppressoutput" => array("arg" => false),
			"help" => array("arg" => false)
		),
		"userinput" => "="
	);
	$args = CLI::ParseCommandLine($options);

	if (isset($args["opts"]["help"]))
	{
		echo "Remoted API server installation command-line tool\n";
		echo "Purpose:  Installs the remoted API server from the command-line.\n";
		echo "\n";
		echo "This tool is question/answer enabled.  Just running it will provide a guided interface.\n";
		echo "\n";
		echo "Syntax:  " . $args["file"] . " [options]\n";
		echo "Options:\n";
		echo "\t-s   Suppress most output.  Useful for capturing JSON output.\n";
		echo "\n";
		echo "Examples:\n";
		echo "\tphp " . $args["file"] . "\n";

		exit();
	}

	$config = RAS_LoadConfig();

	$suppressoutput = (isset($args["opts"]["suppressoutput"]) && $args["opts"]["suppressoutput"]);

	function DisplayResult($result)
	{
		echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

		exit();
	}

	if (!isset($config["server_apikey"]))
	{
		$rng = new CSPRNG(true);
		$config["server_apikey"] = $rng->GenerateString(64);

		RAS_SaveConfig($config);
	}

	if (!isset($config["client_apikey"]))
	{
		$rng = new CSPRNG(true);
		$config["client_apikey"] = $rng->GenerateString(64);

		RAS_SaveConfig($config);
	}

	if (!isset($config["host"]))
	{
		$ipv6 = CLI::GetYesNoUserInputWithArgs($args, "ipv6", "IPv6", "N", "The next question asks if a localhost IPv6 server should be started instead of the default localhost IPv4 server.  A web server will proxy requests to this server.", $suppressoutput);

		$config["host"] = ($ipv6 ? "[::1]" : "127.0.0.1");

		RAS_SaveConfig($config);
	}

	if (!isset($config["port"]))
	{
		$port = (int)CLI::GetUserInputWithArgs($args, "port", "Port", "37791", "The next question asks what port number to run the server on.  A web server will proxy requests to this server and port.", $suppressoutput);
		if ($port < 0 || $port > 65535)  $port = 37791;
		$config["port"] = $port;

		RAS_SaveConfig($config);
	}

	if (function_exists("posix_geteuid"))
	{
		$uid = posix_geteuid();
		if ($uid !== 0)  CLI::DisplayError("The Remoted API Server installer must be run as the 'root' user (UID = 0) to install the system service on *NIX hosts.");
	}

	if (!isset($config["serviceuser"]))
	{
		if (!function_exists("posix_geteuid"))  $config["serviceuser"] = "";
		else
		{
			$serviceuser = CLI::GetUserInputWithArgs($args, "serviceuser", "System service user/group", "remote-api-server", "The next question asks what user the system service will run as.  Both a system user and group will be created.", $suppressoutput);

			$config["serviceuser"] = $serviceuser;

			// Create the system user/group.
			ob_start();
			system("useradd -r -s /bin/false " . escapeshellarg($serviceuser));
			if ($suppressoutput)  ob_end_clean();
			else  ob_end_flush();

			// Allow the group to read the configuration.
			@chgrp($rootpath . "/config.dat", $serviceuser);
		}

		RAS_SaveConfig($config);
	}

	if (!isset($config["servicename"]))
	{
		$servicename = CLI::GetUserInputWithArgs($args, "servicename", "System service name", "remote-api-server", "The next question asks what the name of the system service will be.  Enter a single hyphen '-' to not install this software as a system service at this time.", $suppressoutput);

		if ($servicename !== "-")
		{
			$config["servicename"] = $servicename;

			RAS_SaveConfig($config);

			// Install and start 'server.php' as a system service.
			if (!$suppressoutput)  echo "\nInstalling system service...\n";
			ob_start();
			system(escapeshellarg(PHP_BINARY) . " " . escapeshellarg($rootpath . "/server.php") . " install");
			system(escapeshellarg(PHP_BINARY) . " " . escapeshellarg($rootpath . "/server.php") . " start");
			echo "\n\n";
			if ($suppressoutput)  ob_end_clean();
			else  ob_end_flush();
		}
	}

	$result = array(
		"success" => true,
		"config" => $config,
		"configfile" => $rootpath . "/config.dat"
	);

	DisplayResult($result);
?>