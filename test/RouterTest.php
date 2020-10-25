<?php

namespace Amp\Http\Server\Router\Test;

use Amp\ByteStream\Payload;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Middleware;
use Amp\Http\Server\Options;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Http\Server\Server;
use Amp\Http\Status;
use Amp\Socket;
use League\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface as PsrLogger;

class RouterTest extends TestCase
{
    public function mockServer(): Server
    {
        $options = new Options;

        $socket = Socket\Server::listen('127.0.0.1:0');

        $server = new Server(
            [$socket],
            $this->createMock(RequestHandler::class),
            $this->createMock(PsrLogger::class),
            $options
        );

        return $server;
    }

    public function testThrowsOnInvalidCacheSize(): void
    {
        $this->expectException(\Error::class);

        new Router(0);
    }

    public function testRouteThrowsOnEmptyMethodString(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Amp\Http\Server\Router::addRoute() requires a non-empty string HTTP method at Argument 1');

        $router = new Router;
        $router->addRoute("", "/uri", new CallableRequestHandler(function () {
        }));
    }

    public function testUpdateFailsIfStartedWithoutAnyRoutes(): void
    {
        $mock = $this->mockServer();
        $router = new Router;

        $this->expectException(\Error::class);
        $this->expectDeprecationMessage("Router start failure: no routes registered");

        $router->onStart($mock);
    }

    public function testUseCanonicalRedirector(): void
    {
        $router = new Router;
        $router->addRoute("GET", "/{name}/{age}/?", new CallableRequestHandler(function (Request $req) use (&$routeArgs) {
            $routeArgs = $req->getAttribute(Router::class);
            return new Response;
        }));
        $router->prefix("amphp");

        $router->onStart($this->mockServer());

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
        $router = new Router;
        $router->addRoute("GET", "{name}", new CallableRequestHandler(function (Request $req) use (&$routeArgs) {
            $routeArgs = $req->getAttribute(Router::class);
            return new Response;
        }));
        $router->prefix("amphp");
        $router->prefix("/github/");

        $router->onStart($this->mockServer());

        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/github/amphp/bob"));
        $response = $router->handleRequest($request);

        $this->assertEquals(Status::OK, $response->getStatus());
        $this->assertSame(["name" => "bob"], $routeArgs);
    }

    public function testStack(): void
    {
        $router = new Router;
        $router->addRoute("GET", "/", new CallableRequestHandler(function (Request $req) {
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

        $router->onStart($this->mockServer());

        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/"));
        $response = $router->handleRequest($request);

        $this->assertEquals(Status::OK, $response->getStatus());
        $payload = new Payload($response->getBody());
        $this->assertSame("ab", $payload->buffer());
    }

    public function testStackMultipleCalls(): void
    {
        $router = new Router;
        $router->addRoute("GET", "/", new CallableRequestHandler(function (Request $req) {
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

        $router->onStart($this->mockServer());

        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/"));
        $response = $router->handleRequest($request);

        $this->assertEquals(Status::OK, $response->getStatus());
        $payload = new Payload($response->getBody());
        $this->assertSame("ab", $payload->buffer());
    }

    public function testMerge(): void
    {
        $requestHandler = new CallableRequestHandler(function (Request $req) {
            return new Response(Status::OK, [], $req->getUri()->getPath());
        });

        $routerA = new Router;
        $routerA->prefix("a");
        $routerA->addRoute("GET", "{name}", $requestHandler);

        $routerB = new Router;
        $routerB->prefix("b");
        $routerB->addRoute("GET", "{name}", $requestHandler);

        $routerA->merge($routerB);

        $routerA->onStart($this->mockServer());

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
        $requestHandler = new CallableRequestHandler(function () {
            return new Response(Status::OK);
        });

        $router = new Router;
        $router->addRoute("GET", "/fo+ö", $requestHandler);

        $router->onStart($this->mockServer());

        $uri = "/fo+" . \rawurlencode("ö");

        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString($uri));
        $response = $router->handleRequest($request);
        $this->assertEquals(Status::OK, $response->getStatus());
    }

    public function testFallbackInvokedOnNotFoundRoute(): void
    {
        $requestHandler = new CallableRequestHandler(function () {
            return new Response(Status::OK);
        });

        $fallback = new CallableRequestHandler(function () {
            return new Response(Status::NO_CONTENT);
        });

        $router = new Router;
        $router->addRoute("GET", "/foo/{name}", $requestHandler);
        $router->setFallback($fallback);

        $router->onStart($this->mockServer());

        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/bar"));
        $response = $router->handleRequest($request);
        $this->assertEquals(Status::NO_CONTENT, $response->getStatus());
    }

    public function testNonAllowedMethod(): void
    {
        $requestHandler = new CallableRequestHandler(function () {
            return new Response(Status::OK);
        });

        $router = new Router;
        $router->addRoute("GET", "/foo/{name}", $requestHandler);
        $router->addRoute("DELETE", "/foo/{name}", $requestHandler);

        $router->onStart($this->mockServer());

        $request = new Request($this->createMock(Client::class), "POST", Uri\Http::createFromString("/foo/bar"));
        $response = $router->handleRequest($request);
        $this->assertEquals(Status::METHOD_NOT_ALLOWED, $response->getStatus());
        $this->assertSame('GET, DELETE', $response->getHeader('allow'));
    }

    public function testMergeAfterStart(): void
    {
        $requestHandler = new CallableRequestHandler(function () {
            return new Response(Status::OK);
        });

        $router = new Router;
        $router->addRoute("GET", "/foo/{name}", $requestHandler);

        $router->onStart($this->mockServer());

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot merge routers after');
        $router->merge(new Router);
    }

    public function testPrefixAfterStart(): void
    {
        $requestHandler = new CallableRequestHandler(function () {
            return new Response(Status::OK);
        });

        $router = new Router;
        $router->addRoute("GET", "/foo/{name}", $requestHandler);

        $router->onStart($this->mockServer());

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot alter routes after');
        $router->prefix('/foo');
    }

    public function testAddRouteAfterStart(): void
    {
        $requestHandler = new CallableRequestHandler(function () {
            return new Response(Status::OK);
        });

        $router = new Router;
        $router->addRoute("GET", "/foo/{name}", $requestHandler);

        $router->onStart($this->mockServer());

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot add routes once');
        $router->addRoute("GET", "/foo", $requestHandler);
    }

    public function testStackAfterStart(): void
    {
        $requestHandler = new CallableRequestHandler(function () {
            return new Response(Status::OK);
        });

        $router = new Router;
        $router->addRoute("GET", "/foo/{name}", $requestHandler);

        $router->onStart($this->mockServer());

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot set middlewares');
        $router->stack(new Middleware\CompressionMiddleware);
    }

    public function testSetFallbackAfterStart(): void
    {
        $requestHandler = new CallableRequestHandler(function () {
            return new Response(Status::OK);
        });

        $router = new Router;
        $router->addRoute("GET", "/foo/{name}", $requestHandler);

        $router->onStart($this->mockServer());

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot add fallback');
        $router->setFallback($requestHandler);
    }

    public function testDoubleStart(): void
    {
        $requestHandler = new CallableRequestHandler(function () {
            return new Response(Status::OK);
        });

        $router = new Router;
        $router->addRoute("GET", "/foo/{name}", $requestHandler);

        $router->onStart($this->mockServer());

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Router already started');
        $router->onStart($this->mockServer());
    }
}
