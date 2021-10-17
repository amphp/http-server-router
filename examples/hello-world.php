<?php

require \dirname(__DIR__) . "/vendor/autoload.php";

use Amp\ByteStream\ResourceOutputStream;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Http\Server\Server;
use Amp\Http\Status;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Socket\Server as SocketServer;
use Monolog\Logger;
use function Amp\trapSignal;

// Run this script, then visit http://localhost:1337/ in your browser.

$servers = [
    SocketServer::listen("0.0.0.0:1337"),
    SocketServer::listen("[::]:1337"),
];

$logHandler = new StreamHandler(new ResourceOutputStream(\STDOUT));
$logHandler->setFormatter(new ConsoleFormatter);
$logger = new Logger('server');
$logger->pushHandler($logHandler);

$router = new Router;
$router->addRoute('GET', '/', new CallableRequestHandler(function () {
    return new Response(Status::OK, ['content-type' => 'text/plain'], 'Hello, world!');
}));
$router->addRoute('GET', '/{name}', new CallableRequestHandler(function (Request $request) {
    $args = $request->getAttribute(Router::class);
    return new Response(Status::OK, ['content-type' => 'text/plain'], "Hello, {$args['name']}!");
}));

$server = new Server($servers, $router, $logger);
$server->start();

// Stop the server when SIGINT is received (this is technically optional, but it is best to call Server::stop()).
$signal = trapSignal([SIGINT, SIGTERM]);

$logger->info("Caught signal $signal, stopping server");

$server->stop();
