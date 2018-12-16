<?php
include "vendor/autoload.php";

/*
 * Run `composer install` before using this example.
 * You can point your Apache / IIS root directory directly at this folder.
 */

$request = Zend\Diactoros\ServerRequestFactory::fromGlobals();
$response = new Zend\Diactoros\Response();

$server = new ThunderTUS\Server($request, $response);
$server->setUploadDir(__DIR__ . DIRECTORY_SEPARATOR);
$server->setApiPath("/");
$server->handle();
$response = $server->getResponse();

$emitter = new Zend\HttpHandlerRunner\Emitter\SapiEmitter();
$emitter->emit($response);
