<?php

use ThunderTUS\Store\FileSystem;

include "vendor/autoload.php";

/*
 * Run `composer install` before using this example.
 * You can point your Apache / IIS root directory directly at this folder.
 */

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
