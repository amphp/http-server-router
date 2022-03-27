<?php

namespace Amp\Http\Server\Router\Test;

use Amp\ByteStream\Payload;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\HttpSocketServer;
use Amp\Http\Server\Middleware;
use Amp\Http\Server\Options;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Http\Server\HttpServer;
use Amp\Http\Status;
use Amp\Socket;
use League\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface as PsrLogger;

class RouterTest extends TestCase
{
    public function mockServer(): HttpServer
    {
        $options = new Options;

        $socket = Socket\listen('127.0.0.1:0');

        return new HttpSocketServer(
            [$socket],
            $this->createMock(PsrLogger::class),
            $options
        );
    }

    public function testThrowsOnInvalidCacheSize(): void
    {
        $this->expectException(\Error::class);

        new Router($this->mockServer(), 0);
    }

    public function testRouteThrowsOnEmptyMethodString(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Amp\Http\Server\Router::addRoute() requires a non-empty string HTTP method at Argument 1');

        $router = new Router($this->mockServer());
        $router->addRoute("", "/uri", new ClosureRequestHandler(function () {
        }));
    }

    public function testUpdateFailsIfStartedWithoutAnyRoutes(): void
    {
        $mockServer = $this->mockServer();
        $router = new Router($mockServer);

        $this->expectException(\Error::class);
        $this->expectDeprecationMessage("Router start failure: no routes registered");

        $router->onStart($mockServer);
    }

    public function testUseCanonicalRedirector(): void
    {
        $mockServer = $this->mockServer();

        $router = new Router($mockServer);
        $router->addRoute("GET", "/{name}/{age}/?", new ClosureRequestHandler(function (Request $req) use (&$routeArgs) {
            $routeArgs = $req->getAttribute(Router::class);
            return new Response;
        }));
        $router->prefix("amphp");

        $router->onStart($mockServer);

        // Test that response is redirection
        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/amphp/bob/19/"));

        $response = $router->handleRequest($request);

        $this->assertEquals(Status::PERMANENT_REDIRECT, $response->getStatus());
        $this->assertEquals("/amphp/bob/19", $response->getHeader("location"));

        // Test that response is handled and no redirection
        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/amphp/bob/19"));
        $response = $router->handleRequest($request);

        $this->assertEquals(Status::OK, $response->getStatus());
        $this->assertSame(["name" => "bob", "age" => "19"], $routeArgs);
    }

    public function testMultiplePrefixes(): void
    {
        $mockServer = $this->mockServer();

        $router = new Router($mockServer);
        $router->addRoute("GET", "{name}", new ClosureRequestHandler(function (Request $req) use (&$routeArgs) {
            $routeArgs = $req->getAttribute(Router::class);
            return new Response;
        }));
        $router->prefix("amphp");
        $router->prefix("/github/");

        $router->onStart($mockServer);

        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/github/amphp/bob"));
        $response = $router->handleRequest($request);

        $this->assertEquals(Status::OK, $response->getStatus());
        $this->assertSame(["name" => "bob"], $routeArgs);
    }

    public function testStack(): void
    {
        $mockServer = $this->mockServer();

        $router = new Router($mockServer);
        $router->addRoute("GET", "/", new ClosureRequestHandler(function (Request $req) {
            return new Response(Status::OK, [], $req->getAttribute("stack"));
        }));

        $router->stack(new class implements Middleware {
            public function handleRequest(Request $request, RequestHandler $requestHandler): Response
            {
                $request->setAttribute("stack", "a");
                return $requestHandler->handleRequest($request);
            }
        }, new class implements Middleware {
            public function handleRequest(Request $request, RequestHandler $requestHandler): Response
            {
                $request->setAttribute("stack", $request->getAttribute("stack") . "b");
                return $requestHandler->handleRequest($request);
            }
        });

        $router->onStart($mockServer);

        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/"));
        $response = $router->handleRequest($request);

        $this->assertEquals(Status::OK, $response->getStatus());
        $payload = new Payload($response->getBody());
        $this->assertSame("ab", $payload->buffer());
    }

    public function testStackMultipleCalls(): void
    {
        $mockServer = $this->mockServer();

        $router = new Router($mockServer);
        $router->addRoute("GET", "/", new ClosureRequestHandler(function (Request $req) {
            return new Response(Status::OK, [], $req->getAttribute("stack"));
        }));

        $router->stack(new class implements Middleware {
            public function handleRequest(Request $request, RequestHandler $requestHandler): Response
            {
                $request->setAttribute("stack", $request->getAttribute("stack") . "b");
                return $requestHandler->handleRequest($request);
            }
        });

        $router->stack(new class implements Middleware {
            public function handleRequest(Request $request, RequestHandler $requestHandler): Response
            {
                $request->setAttribute("stack", "a");
                return $requestHandler->handleRequest($request);
            }
        });

        $router->onStart($mockServer);

        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/"));
        $response = $router->handleRequest($request);

        $this->assertEquals(Status::OK, $response->getStatus());
        $payload = new Payload($response->getBody());
        $this->assertSame("ab", $payload->buffer());
    }

    public function testMerge(): void
    {
        $mockServer = $this->mockServer();

        $requestHandler = new ClosureRequestHandler(function (Request $req) {
            return new Response(Status::OK, [], $req->getUri()->getPath());
        });

        $routerA = new Router($mockServer);
        $routerA->prefix("a");
        $routerA->addRoute("GET", "{name}", $requestHandler);

        $routerB = new Router($mockServer);
        $routerB->prefix("b");
        $routerB->addRoute("GET", "{name}", $requestHandler);

        $routerA->merge($routerB);

        $routerA->onStart($mockServer);

        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/a/bob"));
        $response = $routerA->handleRequest($request);
        $this->assertEquals(Status::OK, $response->getStatus());

        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/a/b/bob"));
        $response = $routerA->handleRequest($request);
        $this->assertEquals(Status::OK, $response->getStatus());

        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/b/bob"));
        $response = $routerA->handleRequest($request);
        $this->assertEquals(Status::NOT_FOUND, $response->getStatus());
    }

    public function testPathIsMatchedDecoded(): void
    {
        $requestHandler = new ClosureRequestHandler(function () {
            return new Response(Status::OK);
        });

        $mockServer = $this->mockServer();

        $router = new Router($mockServer);
        $router->addRoute("GET", "/fo+ö", $requestHandler);

        $router->onStart($mockServer);

        $uri = "/fo+" . \rawurlencode("ö");

        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString($uri));
        $response = $router->handleRequest($request);
        $this->assertEquals(Status::OK, $response->getStatus());
    }

    public function testFallbackInvokedOnNotFoundRoute(): void
    {
        $requestHandler = new ClosureRequestHandler(function () {
            return new Response(Status::OK);
        });

        $fallback = new ClosureRequestHandler(function () {
            return new Response(Status::NO_CONTENT);
        });

        $mockServer = $this->mockServer();

        $router = new Router($mockServer);
        $router->addRoute("GET", "/foo/{name}", $requestHandler);
        $router->setFallback($fallback);

        $router->onStart($mockServer);

        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/bar"));
        $response = $router->handleRequest($request);
        $this->assertEquals(Status::NO_CONTENT, $response->getStatus());
    }

    public function testNonAllowedMethod(): void
    {
        $requestHandler = new ClosureRequestHandler(function () {
            return new Response(Status::OK);
        });

        $mockServer = $this->mockServer();

        $router = new Router($mockServer);
        $router->addRoute("GET", "/foo/{name}", $requestHandler);
        $router->addRoute("DELETE", "/foo/{name}", $requestHandler);

        $router->onStart($mockServer);

        $request = new Request($this->createMock(Client::class), "POST", Uri\Http::createFromString("/foo/bar"));
        $response = $router->handleRequest($request);
        $this->assertEquals(Status::METHOD_NOT_ALLOWED, $response->getStatus());
        $this->assertSame('GET, DELETE', $response->getHeader('allow'));
    }

    public function testMergeAfterStart(): void
    {
        $requestHandler = new ClosureRequestHandler(function () {
            return new Response(Status::OK);
        });

        $mockServer = $this->mockServer();

        $router = new Router($mockServer);
        $router->addRoute("GET", "/foo/{name}", $requestHandler);

        $router->onStart($mockServer);

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot merge routers after');
        $router->merge(new Router($mockServer));
    }

    public function testPrefixAfterStart(): void
    {
        $requestHandler = new ClosureRequestHandler(function () {
            return new Response(Status::OK);
        });

        $mockServer = $this->mockServer();

        $router = new Router($mockServer);
        $router->addRoute("GET", "/foo/{name}", $requestHandler);

        $router->onStart($mockServer);

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot alter routes after');
        $router->prefix('/foo');
    }

    public function testAddRouteAfterStart(): void
    {
        $requestHandler = new ClosureRequestHandler(function () {
            return new Response(Status::OK);
        });

        $mockServer = $this->mockServer();

        $router = new Router($mockServer);
        $router->addRoute("GET", "/foo/{name}", $requestHandler);

        $router->onStart($mockServer);

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot add routes once');
        $router->addRoute("GET", "/foo", $requestHandler);
    }

    public function testStackAfterStart(): void
    {
        $requestHandler = new ClosureRequestHandler(function () {
            return new Response(Status::OK);
        });

        $mockServer = $this->mockServer();

        $router = new Router($mockServer);
        $router->addRoute("GET", "/foo/{name}", $requestHandler);

        $router->onStart($mockServer);

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot set middlewares');
        $router->stack(new Middleware\CompressionMiddleware);
    }

    public function testSetFallbackAfterStart(): void
    {
        $requestHandler = new ClosureRequestHandler(function () {
            return new Response(Status::OK);
        });

        $mockServer = $this->mockServer();

        $router = new Router($mockServer);
        $router->addRoute("GET", "/foo/{name}", $requestHandler);

        $router->onStart($mockServer);

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot add fallback');
        $router->setFallback($requestHandler);
    }

    public function testDoubleStart(): void
    {
        $requestHandler = new ClosureRequestHandler(function () {
            return new Response(Status::OK);
        });

        $mockServer = $this->mockServer();

        $router = new Router($mockServer);
        $router->addRoute("GET", "/foo/{name}", $requestHandler);

        $router->onStart($mockServer);

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Router already started');
        $router->onStart($mockServer);
    }
}
