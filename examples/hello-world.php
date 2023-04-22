<?php declare(strict_types=1);

require dirname(__DIR__) . "/vendor/autoload.php";

use Amp\ByteStream;
use Amp\Http\HttpStatus;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Http\Server\SocketHttpServer;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Socket;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use function Amp\trapSignal;

// Run this script, then visit http://localhost:1337/ in your browser.

$logHandler = new StreamHandler(ByteStream\getStdout());
$logHandler->pushProcessor(new PsrLogMessageProcessor());
$logHandler->setFormatter(new ConsoleFormatter());
$logger = new Logger('server');
$logger->pushHandler($logHandler);

$server = SocketHttpServer::createForDirectAccess($logger);

$server->expose(new Socket\InternetAddress("0.0.0.0", 1337));
$server->expose(new Socket\InternetAddress("[::]", 1337));

$errorHandler = new DefaultErrorHandler();

$router = new Router($server, $errorHandler);
$router->addRoute('GET', '/', new ClosureRequestHandler(function () {
    return new Response(HttpStatus::OK, ['content-type' => 'text/plain'], 'Hello, world!');
}));
$router->addRoute('GET', '/{name}', new ClosureRequestHandler(function (Request $request) {
    $args = $request->getAttribute(Router::class);
    return new Response(HttpStatus::OK, ['content-type' => 'text/plain'], "Hello, {$args['name']}!");
}));

$server->start($router, $errorHandler);

// Await SIGINT or SIGTERM to be received.
$signal = trapSignal([SIGINT, SIGTERM]);

$logger->info("Caught signal $signal, stopping server");

$server->stop();
