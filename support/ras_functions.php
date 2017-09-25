<?php
	// Remoted API server support functions.
	// (C) 2017 CubicleSoft.  All Rights Reserved.

	function RAS_LoadConfig()
	{
		global $rootpath;

		if (file_exists($rootpath . "/config.dat"))  $result = json_decode(file_get_contents($rootpath . "/config.dat"), true);
		else  $result = array();
		if (!is_array($result))  $result = array();

		return $result;
	}

	function RAS_SaveConfig($config)
	{
		global $rootpath;

		file_put_contents($rootpath . "/config.dat", json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		@chmod($rootpath . "/config.dat", 0660);
	}
?>