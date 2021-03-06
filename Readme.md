# ThunderTUS PHP

Resumable file upload in PHP using tus resumable upload protocol v1.0.0.

**tus** is a HTTP based protocol for resumable file uploads. Resumable means you can carry on where you left off without re-uploading whole data again in case of any interruptions. An interruption may happen willingly if the user wants to pause, or by accident in case of a network issue or server outage.

**thunder tus** is the most reliable implementation of the tus protocol for PHP yet. Designed for **high concurrency** (real world scenarios) and integration simplicity it's **free of external dependencies** (complex caching engines etc.). It is also **PSR-7 compliant** in order to bring the tus protocol to modern PHP frameworks such as **Slim 3**.

**extensions**: building on the extensibility capabilities of the tus protocol, thunder tus also includes two new extensions:

- **CrossCheck**: final checksum of the uploaded files to ensure maximum reliability;
- **Express**: tus uploads with a single HTTP call - making tus better suited for mobile contexts and other environments where performance is a priority.

## Installation

Pull the package via composer.
```shell
$ composer require TCB13/thunder-tus-php
```

## Basic Usage

Use composer to install `tcb13/thunder-tus-php` and some other packages used in the following examples:
```shell
$ composer require tcb13/thunder-tus-php psr/http-message zendframework/zend-diactoros zendframework/zend-httphandlerrunner
```
Create your `tus-server.php` file:
````php
<?php
include "vendor/autoload.php";

$request = Zend\Diactoros\ServerRequestFactory::fromGlobals();
$response = new Zend\Diactoros\Response();

$backend = new FileSystem(__DIR__ . DIRECTORY_SEPARATOR . "uploads");
$server = new ThunderTUS\Server($request, $response);
$server->setStorageBackend($backend);
$server->setApiPath("/");
$server->handle();
$response = $server->getResponse();

$emitter = new Zend\HttpHandlerRunner\Emitter\SapiEmitter();
$emitter->emit($response);
````
Create the following `.htaccess` (or equivalent) at your virtual host:
````
RewriteEngine on
RewriteBase /
RewriteRule ^(.*)$ tus-server.php [L,QSA]
````
Now you can go ahead and upload a file using the TUS client included at `examples/client-express.php`.
After the upload is finished you may retrieve the file in another script by calling:
````php
$finalStorageDirectory = "/var/www/html/uploads";
$server = new ThunderTUS\Server();
$status = $server->completeAndFetch($filename, $finalStorageDirectory);
if (!$status) {
      throw new \Exception("Could not fetch ({$filename}) from storage backend: not found.");
}
````
The file will be moved from the temporary storage backend to the `$finalStorageDirectory` directory.

You may also retrieve the final file as a stream with `ThunderTUS\Server::completeAndStream()` or keep on the same place as the temporary parts with `ThunderTUS\Server::complete()`

## Storage Backends

In order to use **ThunderTUS you must pick a storage backend**. Those are used to temporally store the uploaded parts until the upload is completed. Storage backends come in a variety of flavours from the local filesystem to MongoBD's GridFS:

- `FileSystem`: a quick to use and understand backend for simple projects that will append uploaded parts into a file stored at the path provided on it's constructor;
- `Amazon S3`: useful in distributed scenarios (eg. your backend serves requests from multiple machines behind a load balancer), an implementation of Amazon's S3 protocol. Tested compatibility with DigitalOcean's Spaces;
- `Redis`:  also for distributed scenarios, stores uploaded parts into a Redis database;
- `MongoDB`: also for distributed scenarios, will store uploaded parts inside a MongoDB GridFS bucket.

You may also implement your own storage backend by extending the `StorageBackend` class and/or implementing the `StorageInterface` interface.

### S3 Storage Backend
````php
$server  = new \ThunderTUS\Server($request, $response);

$client = new S3Client([
    "version"     => "latest",
    "region"      => "...",
    "endpoint"    => "...",
    "credentials" => [
        "key"    => "--key--",
        "secret" => "--secret---",
    ],
]);
$backend  = new S3($client, "your-bucket", "optional-path-prefix");
$server->setStorageBackend($backend);

$server->setUploadMaxFileSize(50000);
$server->setApiPath("/tus");
$server->handle();
`````
You may later retrieve the finished upload as described above at the basic usage section.

### MongoDB Storage Backend
````php
// Connect to your MongDB
$con = new \MongoDB\Client($configs->uri, $configs->options]);
$mongodb= $con->selectDatabase($configs->db]);

// Start ThunderTUS
$server  = new \ThunderTUS\Server($request, $response);

// Load the MongoDB backend
$mongoBackend = new MongoDB($mongodb);
$server->setStorageBackend($mongoBackend );

// Set other settings and process requests
$server->setUploadMaxFileSize(50000);
$server->setApiPath("/tus");
$server->handle();

// Send the response back to the client
$response = $server->getResponse();
````
You may later retrieve the finished upload as described above at the basic usage section.

### Redis Storage Backend
````php
$server  = new \ThunderTUS\Server($request, $response);

$redisBackend = new Redis($redisClient);
$server->setStorageBackend($redisBackend);

$server->setUploadMaxFileSize(50000);
$server->setApiPath("/tus");
$server->handle();
`````
You may later retrieve the finished upload as described above at the basic usage section.

## ThunderTUS & Dependency Injection

ThunderTUS was designed to be integrated into dependency injection systems / containers. 
In simple scenarios you should pass an implementation of a PSR HTTP request and response to ThunderTUS's constructor, however this is optional. Sometimes it might be desirable to be able to instantiate the `Server` in a Service Provider and provide the PSR HTTP implementations later in a controller.

Example of a **ThunderTUS service provider**:

````php
public static function register()
{
    $settings = $this->container->get("settings.tusProtocol");

    // Create the server
    $server = new Server(); // No request or response implementations passed here

    // Load the filesystem Backend
    $backend = new FileSystem($settings->path]);
    $server->setStorageBackend($backend);

    // Set TUS upload parameters
    $server->setUploadMaxFileSize((int)$settings->maxSize);
    $server->setApiPath($settings->endpoint);
    
    return $server;
}
````
Now the **controller that handles uploads**:

````php
public function upload()
{
    // Resolve TUS using the container
    /** @var \ThunderTUS\Server $server */
    $server = $this->container->get(\ThunderTUS\Server::class);

    // Load the request or response implementations here!
    $server->loadHTTPInterfaces($this->request, $this->response); 

    // Handle the upload request
    $server->handle();

    // Send the response back to the client
    return $server->getResponse();
}
````
We've only provided the PSR HTTP request and response implementations on the controller by calling `$server->loadHTTPInterfaces(..)`.

## Client Implementations

- **PHP Client**: At the `examples` directory you may find a simple client and tus-crosscheck / tus-express examples as well;
- **JavaScript / ES6**: https://github.com/stenas/thunder-tus-js-client - a very well designed and implemented tus-crosscheck / tus-express capable client with minimal footprint.
