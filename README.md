Remoted API Server and SDK
==========================

Allows any standard TCP/IP server to be remoted with low-overhead TCP connectivity.  Allows TCP/IP clients to easily and directly connect to a TCP/IP server operating completely behind a firewall by utilizing the [WebRoute protocol](https://github.com/cubiclesoft/webroute).

The Remoted API Server and SDK turn client/server architecture upside down and, in the process, completely bypasses most standard firewall policies.

Features
--------

* A simple system installer.  Includes [Service Manager](https://github.com/cubiclesoft/service-manager) for at-boot startup.
* An easy one-line override `RemotedAPIWebServer` class for anyone already using the CubicleSoft [WebServer](https://github.com/cubiclesoft/ultimate-web-scraper/blob/master/web_server.php) class to build a server.
* A SDK for providing quick connections to Remoted API servers.
* Bypasses most firewall configurations with ease while simultaneously not sacrificing system security.
* Also has a liberal open source license.  MIT or LGPL, your choice.
* Designed for relatively painless integration into your environment.
* Sits on GitHub for all of that pull request and issue tracker goodness to easily submit changes and ideas respectively.

Installation
------------

Installing the Remoted API Server is done from the command line as a system administrator (e.g. root).  Once there, run:

`php install.php`

Which will provide a guided install.  The defaults are usually good enough.  The installer can be re-run at any time (e.g. when upgrading).  To skip installing the server as a system service, enter a singly hyphen '-' when the installer asks.

After installing the server, if it isn't running as a system service or you want to run it manually, run:

`php server.php`

Once the server is running, hook it up to a standard web server as a reverse proxy.  Instructions for how to do this part can be found for most web servers including [Apache](https://httpd.apache.org/docs/2.4/howto/reverse_proxy.html) and [Nginx](https://www.nginx.com/resources/admin-guide/reverse-proxy/).  If you've set up WebSocket servers before (e.g. NodeJS), then the procedure is identical.

Testing
-------

The software includes a test suite that sets up an example server that attaches to the already started Remoted API Server and provides a custom protocol and a client that connects to the server.  To run this test and verify functionality, follow these simple steps using separate terminal/console/command line windows:

* Start the server by running `tests\test_server.php`.  This establishes a WebSocket connection with the Remoted API Server instead of a traditional bind()/accept().
* Start the client by running `tests\test_client.php`.  This establishes a WebRoute connection with the Remoted API Server and retrieves data sent by the server.

These are demos upon which other software can be based.  You can use the source code to both the test server and test client as a starting template for your own integrations.

For additional example integrations in real products, see [Cloud Storage Server](https://github.com/cubiclesoft/cloud-storage-server) and [Cloud Backup](https://github.com/cubiclesoft/cloud-backup).

How It Works
------------

The installer establishes two security tokens:  A server API key and a client API key.  These two tokens should be kept secure as they allow clients to connect and act either as a server or a client depending on which key is used.  The appropriate key is sent as the custom HTTP header `X-Remoted-APIKey`.

A standard TCP/IP server normally makes a call to bind() on a specific port on the local machine and later calls accept() to accept incoming connections.  A Remoted API Server enabled server will, instead, establish a WebSocket connection using the aforementioned server API key with the Remoted API Server and specify the path that it is listening on.  At this point, the server is "waiting" for connections.  It is possible, in many cases only a few adjustments are needed, to transparently replace the bind()/accept() calls with a derived class in whatever language is being used.

Once the WebSocket has been established, a client can connect into the Remoted API Server using the client API key and the same path as the server over the WebRoute protocol.  If the server isn't connected, the client will receive a 502 Bad Gateway response.

Upon receiving a new client upon a path that exists, the server is notified of the connection via the established WebSocket.  Of importance, the WebRoute ID is passed along to the server.  Once the server processes the packet of data, it too now establishes a WebRoute connection (i.e. a brand new TCP/IP connection) with the Remoted API Server.

Once the Remoted API Server links the client and server TCP/IP connections together as per the WebRoute protocol, it lets the data flow in both directions unhindered.  On the server side of things, the new TCP/IP socket is treated as if it had just been returned from an accept() call.

Use Cases
---------

Let's say you want to move some data to a server behind a firewall on an automated basis (e.g. in response to some user action).  However, you don't want to or can't open any ports on the firewall for whatever reason.  Without Remoted API Server, you would have to store that data temporarily outside the firewall and then periodically pick it up using a process running behind the firewall.  There are also complications with making sure that all data is written out to disk before picking up the file as well as making sure that the file is removed/archived in a timely manner once it has been picked up.

Now let's also say you also want to send a response back.  That also means a delay in processing and lots of complications with making sure all the data is there before picking up the response.  Most likely, this sort of thing is done using a cron job running every minute.  Not only is this wasteful of network resources, it creates large, error-prone hurdles in communicating data between the two hosts.

The Remoted API Server allows a server to run behind a firewall but lets public resources connect to it via another server that is accessible without having to open ports on the firewall.  As long as the server/client security tokens stay secure, then it is extremely unlikely that an attacker can get in to do bad things.  The only requirement here is that the host behind the firewall is able connect to a standard web server operating outside of the firewall.

This approach also allows API servers like [Cloud Storage Server](https://github.com/cubiclesoft/cloud-storage-server) to become roaming API servers.  The server itself can relocate to another host at a moment's notice with only a minimal interruption in service.  If clients have built-in retry with random exponential fallback, then the disruption will barely be noticed.  In addition, very complex scenarios such as whitelisted outbound ports, blocked incoming ports, and changing IP addresses can be overcome with relative ease.

Protocol Schemes
----------------

The 'rws://' and 'rwss://' (SSL) protocol schemes are recommended for servers connecting to the Remoted API Server over WebSocket to be able to clearly identify the protocol.  The recommended URL format is:  `rwss://serverapikey@host/webroutepath`.  Where 'webroutepath' is the path that clients will use to contact the server.  If a server is already connected to the incoming path, Remoted API Server will reject the connection.

The 'rwr://' and 'rwrs://' (SSL) protocol schemes are recommended for servers connecting to the Remoted API Server over WebRoute to be able to clearly identify the protocol.  The recommended URL format is:  `rwrs://clientapikey@host/webroutepath`.  Where 'webroutepath' is the same path as the 'webroutepath' used by the server - that is, so the Remoted API Server knows which server to connect the client to.

Security Notes
--------------

This server is designed to completely circumvent firewall infrastructure.  There's no finer way of putting that.  Like most of the sage advice regarding network-enabled software that can be used on the Internet:  Caution is advised when deploying Remoted API Server to production systems.  Know what you are doing when it comes to using software like Remoted API Server and its SDK or it'll come back to haunt you.

Two separate installations of Remoted API Server on a single host allows for "private/internal-only" and "public-ish" API key pairs.  This allows for isolation where some servers are hosted externally and some are hosted internally but both are handled through the same intermediate web host on which the Remoted API Servers run.
