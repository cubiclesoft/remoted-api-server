<?php
	// CubicleSoft PHP WebRouteServer class.
	// (C) 2017 CubicleSoft.  All Rights Reserved.

	class WebRouteServer
	{
		private $fp, $clients, $nextclientid, $unlinkedclients;
		private $maxchunksize, $defaulttimeout;

		const ID_GUID = "BE7204BD-47E6-49EE-9B0D-016E370644B2";

		public function __construct()
		{
			$this->Reset();
		}

		public function Reset()
		{
			$this->fp = false;
			$this->clients = array();
			$this->nextclientid = 1;
			$this->unlinkedclients = array();

			$this->maxchunksize = 65536;
			$this->defaulttimeout = 60;
		}

		public function __destruct()
		{
			$this->Stop();
		}

		public function SetMaxChunkSize($maxsize)
		{
			$this->maxchunksize = (int)$maxsize;
		}

		public function SetDefaultTimeout($defaulttimeout)
		{
			$this->defaulttimeout = (int)$defaulttimeout;
		}

		// Starts the server on the host and port.
		// $host is usually 0.0.0.0 or 127.0.0.1 for IPv4 and [::0] or [::1] for IPv6.
		public function Start($host, $port)
		{
			$this->Stop();

			$this->fp = stream_socket_server("tcp://" . $host . ":" . $port, $errornum, $errorstr);
			if ($this->fp === false)  return array("success" => false, "error" => self::WRTranslate("Bind() failed.  Reason:  %s (%d)", $errorstr, $errornum), "errorcode" => "bind_failed");

			// Enable non-blocking mode.
			stream_set_blocking($this->fp, 0);

			return array("success" => true);
		}

		public function Stop()
		{
			if ($this->fp !== false)
			{
				foreach ($this->clients as $id => $client)
				{
					$this->RemoveClient($id);
				}

				fclose($this->fp);

				$this->clients = array();
				$this->fp = false;
			}

			$this->nextclientid = 1;
			$this->unlinkedclients = array();
		}

		// Dangerous but allows for stream_select() calls on multiple, separate stream handles.
		public function GetStream()
		{
			return $this->fp;
		}

		// Return whatever response/headers are needed here.
		protected function ProcessNewConnection($method, $path, $client)
		{
			$result = "";

			if ($method !== "GET")  $result .= "HTTP/1.1 405 Method Not Allowed\r\n\r\n";
			else if (!isset($client->headers["Host"]) || !isset($client->headers["Connection"]) || stripos($client->headers["Connection"], "upgrade") === false || !isset($client->headers["Upgrade"]) || stripos($client->headers["Upgrade"], "webroute") === false || !isset($client->headers["Webroute-Id"]))
			{
				$result .= "HTTP/1.1 400 Bad Request\r\n\r\n";
			}
			else if (!isset($client->headers["Webroute-Version"]) || $client->headers["Webroute-Version"] != 1)
			{
				$result .= "HTTP/1.1 426 Upgrade Required\r\nWebRoute-Version: 1\r\n\r\n";
			}

			return $result;
		}

		// Return whatever additional HTTP headers are needed here.
		protected function ProcessAcceptedConnection($method, $path, $client)
		{
			return "";
		}

		protected function InitNewClient($fp)
		{
			$client = new stdClass();

			$client->id = $this->nextclientid;
			$client->readdata = "";
			$client->writedata = "";
			$client->state = "request";
			$client->request = false;
			$client->method = "";
			$client->path = "";
			$client->url = "";
			$client->headers = array();
			$client->lastheader = "";
			$client->webrouteid = false;
			$client->linkid = false;
			$client->fp = $fp;
			$client->lastts = microtime(true);
			$client->timeout = $this->defaulttimeout;
			$client->rawrecvsize = 0;
			$client->rawsendsize = 0;

			$this->clients[$this->nextclientid] = $client;

			$this->nextclientid++;

			return $client;
		}

		private function AcceptClient($client)
		{
			$client->writedata .= "HTTP/1.1 101 Switching Protocols\r\nUpgrade: webroute\r\nConnection: Upgrade\r\n";
			$client->writedata .= "Sec-WebRoute-Accept: " . base64_encode(sha1($client->webrouteid . self::ID_GUID, true)) . "\r\n";
			$client->writedata .= $this->ProcessAcceptedConnection($client->method, $client->path, $client);
			$client->writedata .= "\r\n";

			$client->state = "linked";
			$client->lastts = microtime(true);
		}

		private function ProcessInitialResponse($client)
		{
			// Let a derived class handle the new connection (e.g. processing Host).
			$client->writedata .= $this->ProcessNewConnection($client->method, $client->path, $client);

			// If nothing was output, accept the connection.
			if ($client->writedata === "")
			{
				$client->webrouteid = $client->headers["Webroute-Id"];

				// Either establish a link OR register the client with unlinked clients.
				$key = $client->path . ":" . $client->webrouteid;
				if (isset($this->unlinkedclients[$key]))
				{
					$client->linkid = $this->unlinkedclients[$key];

					$client2 = $this->clients[$client->linkid];
					$client2->linkid = $client->id;

					$this->AcceptClient($client);
					$this->AcceptClient($client2);

					// Adjust the WebRoute timeout of both clients to the minimum agreed upon timeout.
					$timeout = (isset($client->headers["Webroute-Timeout"]) && is_numeric($client->headers["Webroute-Timeout"]) && (int)$client->headers["Webroute-Timeout"] > $client->timeout ? (int)$client->headers["Webroute-Timeout"] : $client->timeout);
					$timeout2 = (isset($client2->headers["Webroute-Timeout"]) && is_numeric($client2->headers["Webroute-Timeout"]) && (int)$client2->headers["Webroute-Timeout"] > $client2->timeout ? (int)$client2->headers["Webroute-Timeout"] : $client2->timeout);
					$timeout = min($timeout, $timeout2);
					$client->timeout = $timeout;
					$client2->timeout = $timeout;

					unset($this->unlinkedclients[$key]);
				}
				else
				{
					$this->unlinkedclients[$key] = $client->id;

					$client->state = "waiting";
				}

				return true;
			}

			return false;
		}

		public function UpdateStreamsAndTimeout($prefix, &$timeout, &$readfps, &$writefps)
		{
			if ($this->fp !== false)  $readfps[$prefix . "wr_s"] = $this->fp;
			if ($timeout === false || $timeout > $this->defaulttimeout)  $timeout = $this->defaulttimeout;

			foreach ($this->clients as $id => $client)
			{
				if ($client->state === "request" || $client->state === "waiting" || ($client->state === "linked" && strlen($this->clients[$client->linkid]->writedata) < $this->maxchunksize))  $readfps[$prefix . "wr_c_" . $id] = $client->fp;

				if ($client->writedata !== "")  $writefps[$prefix . "wr_c_" . $id] = $client->fp;

				if ($client->state === "closing" && $client->writedata === "")  $timeout = 0;
			}
		}

		// Sometimes keyed arrays don't work properly.
		public static function FixedStreamSelect(&$readfps, &$writefps, &$exceptfps, $timeout)
		{
			// In order to correctly detect bad outputs, no '0' integer key is allowed.
			if (isset($readfps[0]) || isset($writefps[0]) || ($exceptfps !== NULL && isset($exceptfps[0])))  return false;

			$origreadfps = $readfps;
			$origwritefps = $writefps;
			$origexceptfps = $exceptfps;

			$result2 = @stream_select($readfps, $writefps, $exceptfps, $timeout);
			if ($result2 === false)  return false;

			if (isset($readfps[0]))
			{
				$fps = array();
				foreach ($origreadfps as $key => $fp)  $fps[(int)$fp] = $key;

				foreach ($readfps as $num => $fp)
				{
					$readfps[$fps[(int)$fp]] = $fp;

					unset($readfps[$num]);
				}
			}

			if (isset($writefps[0]))
			{
				$fps = array();
				foreach ($origwritefps as $key => $fp)  $fps[(int)$fp] = $key;

				foreach ($writefps as $num => $fp)
				{
					$writefps[$fps[(int)$fp]] = $fp;

					unset($writefps[$num]);
				}
			}

			if ($exceptfps !== NULL && isset($exceptfps[0]))
			{
				$fps = array();
				foreach ($origexceptfps as $key => $fp)  $fps[(int)$fp] = $key;

				foreach ($exceptfps as $num => $fp)
				{
					$exceptfps[$fps[(int)$fp]] = $fp;

					unset($exceptfps[$num]);
				}
			}

			return true;
		}

		// Handles new connections, the initial conversation, basic packet management, and timeouts.
		// Can wait on more streams than just sockets and/or more sockets.  Useful for waiting on other resources.
		// 'wr_s' and the 'wr_c_' prefix are reserved.
		// Returns an array of clients that may need more processing.
		public function Wait($timeout = false, $readfps = array(), $writefps = array(), $exceptfps = NULL)
		{
			$this->UpdateStreamsAndTimeout("", $timeout, $readfps, $writefps);

			$result = array("success" => true, "clients" => array(), "removed" => array(), "readfps" => array(), "writefps" => array(), "exceptfps" => array());
			if (!count($readfps) && !count($writefps))  return $result;

			$result2 = self::FixedStreamSelect($readfps, $writefps, $exceptfps, $timeout);
			if ($result2 === false)  return array("success" => false, "error" => self::WRTranslate("Wait() failed due to stream_select() failure.  Most likely cause:  Connection failure."), "errorcode" => "stream_select_failed");

			// Handle new connections.
			if (isset($readfps["wr_s"]))
			{
				while (($fp = @stream_socket_accept($this->fp, 0)) !== false)
				{
					// Enable non-blocking mode.
					stream_set_blocking($fp, 0);

					$this->InitNewClient($fp);
				}

				unset($readfps["wr_s"]);
			}

			// Handle clients in the read queue.
			foreach ($readfps as $cid => $fp)
			{
				if (!is_string($cid) || strlen($cid) < 6 || substr($cid, 0, 5) !== "wr_c_")  continue;

				$id = (int)substr($cid, 5);

				if (!isset($this->clients[$id]))  continue;

				$client = $this->clients[$id];

				if ($client->state === "linked")
				{
					$result2 = @fread($fp, 8192);
					if ($result2 === false || ($result2 === "" && feof($fp)))
					{
						$this->RemoveClient($id);

						$result["removed"][$id] = array("result" => array("success" => false, "error" => self::WRTranslate("Client fread() failure.  Most likely cause:  Connection failure."), "errorcode" => "fread_failed"), "client" => $client);
					}
					else
					{
						$this->clients[$client->linkid]->writedata .= $result2;

						$client->lastts = microtime(true);
						$client->rawrecvsize += strlen($result2);

						$result["clients"][$id] = $client;
					}
				}
				else if ($client->state === "waiting")
				{
					$result2 = @fread($fp, 0);
					if ($result2 === false || ($result2 === "" && feof($fp)))
					{
						$this->RemoveClient($id);

						$result["removed"][$id] = array("result" => array("success" => false, "error" => self::WRTranslate("Client fread() failure.  Most likely cause:  Connection failure."), "errorcode" => "fread_failed"), "client" => $client);
					}
				}
				else if ($client->state === "request")
				{
					$result2 = @fread($fp, 8192);
					if ($result2 === false || ($result2 === "" && feof($fp)))  $this->RemoveClient($id);
					else
					{
						$client->readdata .= $result2;
						$client->lastts = microtime(true);
						$client->rawrecvsize += strlen($result2);

						if (strlen($client->readdata) > 100000)
						{
							// Bad header size.  Just kill the connection.
							@fclose($fp);

							unset($this->clients[$id]);
						}
						else
						{
							while (($pos = strpos($client->readdata, "\n")) !== false)
							{
								// Retrieve the next line of input.
								$line = rtrim(substr($client->readdata, 0, $pos));
								$client->readdata = (string)substr($client->readdata, $pos + 1);

								if ($client->request === false)  $client->request = trim($line);
								else if ($line !== "")
								{
									// Process the header.
									if ($client->lastheader != "" && (substr($line, 0, 1) == " " || substr($line, 0, 1) == "\t"))  $client->headers[$client->lastheader] .= $header;
									else
									{
										$pos = strpos($line, ":");
										if ($pos === false)  $pos = strlen($line);
										$client->lastheader = self::HeaderNameCleanup(substr($line, 0, $pos));
										$client->headers[$client->lastheader] = ltrim(substr($line, $pos + 1));
									}
								}
								else
								{
									// Headers have all been received.  Process the client request.
									$request = $client->request;
									$pos = strpos($request, " ");
									if ($pos === false)  $pos = strlen($request);
									$method = (string)substr($request, 0, $pos);
									$request = trim(substr($request, $pos));

									$pos = strrpos($request, " ");
									if ($pos === false)  $pos = strlen($request);
									$path = (string)substr($request, 0, $pos);
									if ($path === "")  $path = "/";

									if (isset($client->headers["Host"]))  $client->headers["Host"] = preg_replace('/[^a-z0-9.:\[\]_-]/', "", strtolower($client->headers["Host"]));

									$client->method = $method;
									$client->path = $path;
									$client->url = "wr://" . (isset($client->headers["Host"]) ? $client->headers["Host"] : "localhost") . $path;

									if ($this->ProcessInitialResponse($client))  $result["clients"][$id] = $client;

									break;
								}
							}
						}
					}
				}

				unset($readfps[$cid]);
			}

			// Handle remaining clients in the write queue.
			foreach ($writefps as $cid => $fp)
			{
				if (!is_string($cid) || strlen($cid) < 6 || substr($cid, 0, 5) !== "wr_c_")  continue;

				$id = (int)substr($cid, 5);

				if (!isset($this->clients[$id]))  continue;

				$client = $this->clients[$id];

				if ($client->writedata !== "")
				{
					$result2 = @fwrite($fp, $client->writedata);
					if ($result2 === false || ($result2 === "" && feof($fp)))
					{
						$this->RemoveClient($id);

						$result["removed"][$id] = array("result" => array("success" => false, "error" => self::WRTranslate("Client fwrite() failure.  Most likely cause:  Connection failure."), "errorcode" => "fwrite_failed"), "client" => $client);
					}
					else
					{
						$client->writedata = (string)substr($client->writedata, $result2);
						$client->lastts = microtime(true);
						$client->rawsendsize += $result2;

						$result["clients"][$id] = $client;
					}
				}

				unset($writefps[$cid]);
			}

			// Handle client timeouts.
			foreach ($this->clients as $id => $client)
			{
				if (($client->state === "closing" && $client->writedata === "") || (!isset($result["clients"][$id]) && $client->lastts < microtime(true) - $client->timeout))
				{
					if ($client->state === "waiting")
					{
						// Send a failure response.
						$client->writedata .= "HTTP/1.1 504 Gateway Timeout\r\nWebRoute-Version: 1\r\nConnection: close\r\n\r\n";

						$client->state = "closing";

						$key = $client->path . ":" . $client->webrouteid;
						unset($this->unlinkedclients[$key]);
					}
					else
					{
						$this->RemoveClient($id);

						$result["removed"][$id] = array("result" => array("success" => false, "error" => self::WRTranslate("Client timeout.  Most likely cause:  Connection failure."), "errorcode" => "connection_timeout"), "client" => $client);
					}
				}
			}

			// Return any extra handles that were being waited on.
			$result["readfps"] = $readfps;
			$result["writefps"] = $writefps;
			$result["exceptfps"] = $exceptfps;

			return $result;
		}

		public function GetClients()
		{
			return $this->clients;
		}

		public function GetClient($id)
		{
			return (isset($this->clients[$id]) ? $this->clients[$id] : false);
		}

		public function RemoveClient($id)
		{
			if (isset($this->clients[$id]))
			{
				$client = $this->clients[$id];

				if ($client->fp !== false)  @fclose($client->fp);

				if ($client->linkid !== false)
				{
					$this->clients[$client->linkid]->linkid = false;
					$this->clients[$client->linkid]->state = "closing";
				}
				else if ($client->state === "waiting")
				{
					$key = $client->path . ":" . $client->webrouteid;

					unset($this->unlinkedclients[$key]);
				}

				unset($this->clients[$id]);
			}
		}

		public function ProcessWebServerClientUpgrade($webserver, $client, $linkexists = false)
		{
			if (!($client instanceof WebServer_Client))  return false;

			if (!$client->requestcomplete || $client->mode === "handle_response")  return false;
			if ($client->request["method"] !== "GET" || !isset($client->headers["Connection"]) || stripos($client->headers["Connection"], "upgrade") === false || !isset($client->headers["Upgrade"]) || stripos($client->headers["Upgrade"], "webroute") === false)  return false;

			// Only attempt the upgrade if a link already exists.
			// Useful for preventing timeouts on broken connections.
			if ($linkexists && isset($client->headers["Webroute-Id"]) && !isset($this->unlinkedclients[$client->request["path"] . ":" . $client->headers["Webroute-Id"]]))  return false;

			// Create an equivalent WebRoute server client class.
			$webserver->DetachClient($client->id);

			$method = $client->request["method"];
			$path = $client->request["path"];

			$client2 = $this->InitNewClient($client->fp);
			$client2->request = $client->request["line"];
			$client2->headers = $client->headers;
			$client2->method = $method;
			$client2->path = $path;
			$client2->url = "wr://" . (isset($client->headers["Host"]) ? $client->headers["Host"] : "localhost") . $path;

			$this->ProcessInitialResponse($client2);

			return $client2->id;
		}

		public static function HeaderNameCleanup($name)
		{
			return preg_replace('/\s+/', "-", ucwords(strtolower(trim(preg_replace('/[^A-Za-z0-9 ]/', " ", $name)))));
		}

		public static function WRTranslate()
		{
			$args = func_get_args();
			if (!count($args))  return "";

			return call_user_func_array((defined("CS_TRANSLATE_FUNC") && function_exists(CS_TRANSLATE_FUNC) ? CS_TRANSLATE_FUNC : "sprintf"), $args);
		}
	}
?>