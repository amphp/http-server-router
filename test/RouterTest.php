<?php declare(strict_types=1);

namespace Amp\Http\Server;

use Amp\ByteStream\Payload;
use Amp\CompositeException;
use Amp\Http\HttpStatus;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Socket\InternetAddress;
use League\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class RouterTest extends TestCase
{
    private SocketHttpServer $server;
    private ErrorHandler $errorHandler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = $this->createMockServer();
        $this->errorHandler = $this->createErrorHandler();
    }

    protected function createMockServer(): SocketHttpServer
    {
        $server = SocketHttpServer::createForDirectAccess(new NullLogger);
        $server->expose(new InternetAddress('127.0.0.1', 0));
        return $server;
    }

    protected function createErrorHandler(): ErrorHandler
    {
        return new DefaultErrorHandler();
    }

    public function testThrowsOnInvalidCacheSize(): void
    {
        $this->expectException(\Error::class);

        new Router($this->createMockServer(), $this->createErrorHandler(), 0);
    }

    public function testRouteThrowsOnEmptyMethodString(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Amp\Http\Server\Router::addRoute() requires a non-empty string HTTP method at Argument 1');

        $router = new Router($this->createMockServer(), $this->createErrorHandler());
        $router->addRoute("", "/uri", new ClosureRequestHandler(function () {
        }));
    }

    public function testUpdateFailsIfStartedWithoutAnyRoutes(): void
    {
        $router = new Router($this->server, $this->errorHandler);

        $this->expectException(CompositeException::class);
        $this->expectExceptionMessage("Router start failure: no routes registered");

        $this->server->start($router, $this->errorHandler);
    }

    public function testUseCanonicalRedirector(): void
    {
        $router = new Router($this->server, $this->errorHandler);
        $router->addRoute("GET", "/{name}/{age}/?", new ClosureRequestHandler(function (Request $req) use (&$routeArgs) {
            $routeArgs = $req->getAttribute(Router::class);
            return new Response;
        }));
        $router->prefix("amphp");

        $this->server->start($router, $this->errorHandler);

        // Test that response is redirection
        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/amphp/bob/19/"));

        $response = $router->handleRequest($request);

        $this->assertEquals(HttpStatus::PERMANENT_REDIRECT, $response->getStatus());
        $this->assertEquals("/amphp/bob/19", $response->getHeader("location"));

        // Test that response is handled and no redirection
        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/amphp/bob/19"));
        $response = $router->handleRequest($request);

        $this->assertEquals(HttpStatus::OK, $response->getStatus());
        $this->assertSame(["name" => "bob", "age" => "19"], $routeArgs);
    }

    public function testMultiplePrefixes(): void
    {
        $router = new Router($this->server, $this->errorHandler);
        $router->addRoute("GET", "{name}", new ClosureRequestHandler(function (Request $req) use (&$routeArgs) {
            $routeArgs = $req->getAttribute(Router::class);
            return new Response;
        }));
        $router->prefix("amphp");
        $router->prefix("/github/");

        $this->server->start($router, $this->errorHandler);

        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/github/amphp/bob"));
        $response = $router->handleRequest($request);

        $this->assertEquals(HttpStatus::OK, $response->getStatus());
        $this->assertSame(["name" => "bob"], $routeArgs);
    }

    public function testStack(): void
    {
        $router = new Router($this->server, $this->errorHandler);
        $router->addRoute("GET", "/", new ClosureRequestHandler(function (Request $req) {
            return new Response(HttpStatus::OK, [], $req->getAttribute("stack"));
        }));

        $router->stackMiddleware(new class implements Middleware {
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

        $this->server->start($router, $this->errorHandler);

        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/"));
        $response = $router->handleRequest($request);

        $this->assertEquals(HttpStatus::OK, $response->getStatus());
        $payload = new Payload($response->getBody());
        $this->assertSame("ab", $payload->buffer());
    }

    public function testStackMultipleCalls(): void
    {
        $router = new Router($this->server, $this->errorHandler);
        $router->addRoute("GET", "/", new ClosureRequestHandler(function (Request $req) {
            return new Response(HttpStatus::OK, [], $req->getAttribute("stack"));
        }));

        $router->stackMiddleware(new class implements Middleware {
            public function handleRequest(Request $request, RequestHandler $requestHandler): Response
            {
                $request->setAttribute("stack", $request->getAttribute("stack") . "b");
                return $requestHandler->handleRequest($request);
            }
        });

        $router->stackMiddleware(new class implements Middleware {
            public function handleRequest(Request $request, RequestHandler $requestHandler): Response
            {
                $request->setAttribute("stack", "a");
                return $requestHandler->handleRequest($request);
            }
        });

        $this->server->start($router, $this->errorHandler);

        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/"));
        $response = $router->handleRequest($request);

        $this->assertEquals(HttpStatus::OK, $response->getStatus());
        $payload = new Payload($response->getBody());
        $this->assertSame("ab", $payload->buffer());
    }

    public function testMerge(): void
    {
        $requestHandler = new ClosureRequestHandler(function (Request $req) {
            return new Response(HttpStatus::OK, [], $req->getUri()->getPath());
        });

        $routerA = new Router($this->server, $this->createErrorHandler());
        $routerA->prefix("a");
        $routerA->addRoute("GET", "{name}", $requestHandler);

        $routerB = new Router($this->server, $this->createErrorHandler());
        $routerB->prefix("b");
        $routerB->addRoute("GET", "{name}", $requestHandler);

        $routerA->merge($routerB);

        $this->server->start($routerA, $this->errorHandler);

        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/a/bob"));
        $response = $routerA->handleRequest($request);
        $this->assertEquals(HttpStatus::OK, $response->getStatus());

        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/a/b/bob"));
        $response = $routerA->handleRequest($request);
        $this->assertEquals(HttpStatus::OK, $response->getStatus());

        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/b/bob"));
        $response = $routerA->handleRequest($request);
        $this->assertEquals(HttpStatus::NOT_FOUND, $response->getStatus());
    }

    public function testPathIsMatchedDecoded(): void
    {
        $requestHandler = new ClosureRequestHandler(function () {
            return new Response(HttpStatus::OK);
        });

        $router = new Router($this->server, $this->errorHandler);
        $router->addRoute("GET", "/fo+ö", $requestHandler);

        $this->server->start($router, $this->errorHandler);

        $uri = "/fo+" . \rawurlencode("ö");

        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString($uri));
        $response = $router->handleRequest($request);
        $this->assertEquals(HttpStatus::OK, $response->getStatus());
    }

    public function testFallbackInvokedOnNotFoundRoute(): void
    {
        $requestHandler = new ClosureRequestHandler(function () {
            return new Response(HttpStatus::OK);
        });

        $fallback = new ClosureRequestHandler(function () {
            return new Response(HttpStatus::NO_CONTENT);
        });

        $router = new Router($this->server, $this->errorHandler);
        $router->addRoute("GET", "/foo/{name}", $requestHandler);
        $router->setFallback($fallback);

        $this->server->start($router, $this->errorHandler);

        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/bar"));
        $response = $router->handleRequest($request);
        $this->assertEquals(HttpStatus::NO_CONTENT, $response->getStatus());
    }

    public function testNonAllowedMethod(): void
    {
        $requestHandler = new ClosureRequestHandler(function () {
            return new Response(HttpStatus::OK);
        });

        $router = new Router($this->server, $this->errorHandler);
        $router->addRoute("GET", "/foo/{name}", $requestHandler);
        $router->addRoute("DELETE", "/foo/{name}", $requestHandler);

        $this->server->start($router, $this->errorHandler);

        $request = new Request($this->createMock(Client::class), "POST", Uri\Http::createFromString("/foo/bar"));
        $response = $router->handleRequest($request);
        $this->assertEquals(HttpStatus::METHOD_NOT_ALLOWED, $response->getStatus());
        $this->assertSame('GET, DELETE', $response->getHeader('allow'));
    }

    public function testMergeAfterStart(): void
    {
        $requestHandler = new ClosureRequestHandler(function () {
            return new Response(HttpStatus::OK);
        });

        $mockServer = $this->createMockServer();

        $router = new Router($this->server, $this->errorHandler);
        $router->addRoute("GET", "/foo/{name}", $requestHandler);

        $this->server->start($router, $this->errorHandler);

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot merge routers after');
        $router->merge(new Router($mockServer, $this->createErrorHandler()));
    }

    public function testPrefixAfterStart(): void
    {
        $requestHandler = new ClosureRequestHandler(function () {
            return new Response(HttpStatus::OK);
        });

        $router = new Router($this->server, $this->errorHandler);
        $router->addRoute("GET", "/foo/{name}", $requestHandler);

        $this->server->start($router, $this->errorHandler);

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot alter routes after');
        $router->prefix('/foo');
    }

    public function testAddRouteAfterStart(): void
    {
        $requestHandler = new ClosureRequestHandler(function () {
            return new Response(HttpStatus::OK);
        });

        $router = new Router($this->server, $this->errorHandler);
        $router->addRoute("GET", "/foo/{name}", $requestHandler);

        $this->server->start($router, $this->errorHandler);

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot add routes once');
        $router->addRoute("GET", "/foo", $requestHandler);
    }

    public function testStackAfterStart(): void
    {
        $requestHandler = new ClosureRequestHandler(function () {
            return new Response(HttpStatus::OK);
        });

        $router = new Router($this->server, $this->errorHandler);
        $router->addRoute("GET", "/foo/{name}", $requestHandler);

        $this->server->start($router, $this->errorHandler);

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot set middlewares');
        $router->stackMiddleware(new Middleware\CompressionMiddleware);
    }

    public function testSetFallbackAfterStart(): void
    {
        $requestHandler = new ClosureRequestHandler(function () {
            return new Response(HttpStatus::OK);
        });

        $router = new Router($this->server, $this->errorHandler);
        $router->addRoute("GET", "/foo/{name}", $requestHandler);

        $this->server->start($router, $this->errorHandler);

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot add fallback');
        $router->setFallback($requestHandler);
    }
}
