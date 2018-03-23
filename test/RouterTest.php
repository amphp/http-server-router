<?php

namespace Amp\Http\Server\Router\Test;

use Amp\ByteStream\Payload;
use Amp\Failure;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Middleware;
use Amp\Http\Server\Options;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Http\Server\Server;
use Amp\Http\Status;
use Amp\Promise;
use Amp\Socket;
use League\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface as PsrLogger;

class RouterTest extends TestCase {
    public function mockServer(): Server {
        $options = new Options;

        $socket = $this->createMock(Socket\Server::class);

        $server = new Server(
            [$socket],
            $this->createMock(RequestHandler::class),
            $this->createMock(PsrLogger::class),
            $options
        );

        return $server;
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage Amp\Http\Server\Router::addRoute() requires a non-empty string HTTP method at Argument 1
     */
    public function testRouteThrowsOnEmptyMethodString() {
        $router = new Router;
        $router->addRoute("", "/uri", new CallableRequestHandler(function () {}));
    }

    public function testUpdateFailsIfStartedWithoutAnyRoutes() {
        $router = new Router;
        $mock = $this->mockServer();
        $result = $router->onStart($mock);
        $this->assertInstanceOf(Failure::class, $result);
        $i = 0;
        $result->onResolve(function (\Throwable $e) use (&$i) {
            $i++;
            $this->assertInstanceOf("Error", $e);
            $this->assertSame("Router start failure: no routes registered", $e->getMessage());
        });
        $this->assertSame($i, 1);
    }

    public function testUseCanonicalRedirector() {
        $router = new Router;
        $router->addRoute("GET", "/{name}/{age}/?", new CallableRequestHandler(function (Request $req) use (&$routeArgs) {
            $routeArgs = $req->getAttribute(Router::class);
            return new Response;
        }));
        $router->prefix("amphp");

        Promise\wait($router->onStart($this->mockServer()));

        // Test that response is redirection
        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/amphp/bob/19/"));
        /** @var \Amp\Http\Server\Response $response */
        $response = Promise\wait($router->handleRequest($request));

        $this->assertEquals(Status::PERMANENT_REDIRECT, $response->getStatus());
        $this->assertEquals("/amphp/bob/19", $response->getHeader("location"));

        // Test that response is handled and no redirection
        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/amphp/bob/19"));
        $response = Promise\wait($router->handleRequest($request));

        $this->assertEquals(Status::OK, $response->getStatus());
        $this->assertSame(["name" => "bob", "age" => "19"], $routeArgs);
    }

    public function testMultiplePrefixes() {
        $router = new Router;
        $router->addRoute("GET", "{name}", new CallableRequestHandler(function (Request $req) use (&$routeArgs) {
            $routeArgs = $req->getAttribute(Router::class);
            return new Response;
        }));
        $router->prefix("amphp");
        $router->prefix("/github/");

        Promise\wait($router->onStart($this->mockServer()));

        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/github/amphp/bob"));
        /** @var \Amp\Http\Server\Response $response */
        $response = Promise\wait($router->handleRequest($request));

        $this->assertEquals(Status::OK, $response->getStatus());
        $this->assertSame(["name" => "bob"], $routeArgs);
    }

    public function testStack() {
        $router = new Router;
        $router->addRoute("GET", "/", new CallableRequestHandler(function (Request $req) {
            return new Response(Status::OK, [], $req->getAttribute("stack"));
        }));

        $router->stack(new class implements Middleware {
            public function handleRequest(Request $request, RequestHandler $requestHandler): Promise {
                $request->setAttribute("stack", "a");
                return $requestHandler->handleRequest($request);
            }
        }, new class implements Middleware {
            public function handleRequest(Request $request, RequestHandler $requestHandler): Promise {
                $request->setAttribute("stack", $request->getAttribute("stack") . "b");
                return $requestHandler->handleRequest($request);
            }
        });

        Promise\wait($router->onStart($this->mockServer()));

        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/"));
        /** @var \Amp\Http\Server\Response $response */
        $response = Promise\wait($router->handleRequest($request));

        $this->assertEquals(Status::OK, $response->getStatus());
        $payload = new Payload($response->getBody());
        $this->assertSame("ab", Promise\wait($payload->buffer()));
    }

    public function testStackMultipleCalls() {
        $router = new Router;
        $router->addRoute("GET", "/", new CallableRequestHandler(function (Request $req) {
            return new Response(Status::OK, [], $req->getAttribute("stack"));
        }));

        $router->stack(new class implements Middleware {
            public function handleRequest(Request $request, RequestHandler $requestHandler): Promise {
                $request->setAttribute("stack", $request->getAttribute("stack") . "b");
                return $requestHandler->handleRequest($request);
            }
        });

        $router->stack(new class implements Middleware {
            public function handleRequest(Request $request, RequestHandler $requestHandler): Promise {
                $request->setAttribute("stack", "a");
                return $requestHandler->handleRequest($request);
            }
        });

        Promise\wait($router->onStart($this->mockServer()));

        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/"));
        /** @var \Amp\Http\Server\Response $response */
        $response = Promise\wait($router->handleRequest($request));

        $this->assertEquals(Status::OK, $response->getStatus());
        $payload = new Payload($response->getBody());
        $this->assertSame("ab", Promise\wait($payload->buffer()));
    }

    public function testMerge() {
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

        Promise\wait($routerA->onStart($this->mockServer()));

        /** @var \Amp\Http\Server\Response $response */
        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/a/bob"));
        $response = Promise\wait($routerA->handleRequest($request));
        $this->assertEquals(Status::OK, $response->getStatus());

        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/a/b/bob"));
        $response = Promise\wait($routerA->handleRequest($request));
        $this->assertEquals(Status::OK, $response->getStatus());

        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/b/bob"));
        $response = Promise\wait($routerA->handleRequest($request));
        $this->assertEquals(Status::NOT_FOUND, $response->getStatus());
    }
}
