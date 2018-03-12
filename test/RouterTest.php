<?php

namespace Amp\Http\Server\Router\Test;

use Amp\ByteStream\Message;
use Amp\Failure;
use Amp\Http\Server\CallableResponder;
use Amp\Http\Server\Client;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Middleware;
use Amp\Http\Server\Options;
use Amp\Http\Server\Request;
use Amp\Http\Server\Responder;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Http\Server\Server;
use Amp\Http\Status;
use Amp\Promise;
use League\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface as PsrLogger;

class RouterTest extends TestCase {
    public function mockServer(): Server {
        $options = new Options;

        $mock = $this->getMockBuilder(Server::class)
            ->setConstructorArgs([$this->createMock(Responder::class), $options, $this->createMock(PsrLogger::class)])
            ->getMock();

        $mock->method("getOptions")
            ->willReturn($options);

        $mock->method("getErrorHandler")
            ->willReturn(new DefaultErrorHandler);

        return $mock;
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage Amp\Http\Server\Router::addRoute() requires a non-empty string HTTP method at Argument 1
     */
    public function testRouteThrowsOnEmptyMethodString() {
        $router = new Router;
        $router->addRoute("", "/uri", new CallableResponder(function () {}));
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
        $router->addRoute("GET", "/{name}/{age}/?", new CallableResponder(function (Request $req) use (&$routeArgs) {
            $routeArgs = $req->getAttribute(Router::class);
            return new Response;
        }));
        $router->prefix("amphp");

        Promise\wait($router->onStart($this->mockServer()));

        // Test that response is redirection
        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/amphp/bob/19/"));
        /** @var \Amp\Http\Server\Response $response */
        $response = Promise\wait($router->respond($request));

        $this->assertEquals(Status::PERMANENT_REDIRECT, $response->getStatus());
        $this->assertEquals("/amphp/bob/19", $response->getHeader("location"));

        // Test that response is handled and no redirection
        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/amphp/bob/19"));
        $response = Promise\wait($router->respond($request));

        $this->assertEquals(Status::OK, $response->getStatus());
        $this->assertSame(["name" => "bob", "age" => "19"], $routeArgs);
    }

    public function testMultiplePrefixes() {
        $router = new Router;
        $router->addRoute("GET", "{name}", new CallableResponder(function (Request $req) use (&$routeArgs) {
            $routeArgs = $req->getAttribute(Router::class);
            return new Response;
        }));
        $router->prefix("amphp");
        $router->prefix("/github/");

        Promise\wait($router->onStart($this->mockServer()));

        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/github/amphp/bob"));
        /** @var \Amp\Http\Server\Response $response */
        $response = Promise\wait($router->respond($request));

        $this->assertEquals(Status::OK, $response->getStatus());
        $this->assertSame(["name" => "bob"], $routeArgs);
    }

    public function testStack() {
        $router = new Router;
        $router->addRoute("GET", "/", new CallableResponder(function (Request $req) {
            return new Response($req->getAttribute("stack"));
        }));

        $router->stack(new class implements Middleware {
            public function process(Request $request, Responder $responder): Promise {
                $request->setAttribute("stack", "a");
                return $responder->respond($request);
            }
        }, new class implements Middleware {
            public function process(Request $request, Responder $responder): Promise {
                $request->setAttribute("stack", $request->getAttribute("stack") . "b");
                return $responder->respond($request);
            }
        });

        Promise\wait($router->onStart($this->mockServer()));

        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/"));
        /** @var \Amp\Http\Server\Response $response */
        $response = Promise\wait($router->respond($request));

        $this->assertEquals(Status::OK, $response->getStatus());
        $this->assertSame("ab", Promise\wait(new Message($response->getBody())));
    }

    public function testStackMultipleCalls() {
        $router = new Router;
        $router->addRoute("GET", "/", new CallableResponder(function (Request $req) {
            return new Response($req->getAttribute("stack"));
        }));

        $router->stack(new class implements Middleware {
            public function process(Request $request, Responder $responder): Promise {
                $request->setAttribute("stack", $request->getAttribute("stack") . "b");
                return $responder->respond($request);
            }
        });

        $router->stack(new class implements Middleware {
            public function process(Request $request, Responder $responder): Promise {
                $request->setAttribute("stack", "a");
                return $responder->respond($request);
            }
        });

        Promise\wait($router->onStart($this->mockServer()));

        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/"));
        /** @var \Amp\Http\Server\Response $response */
        $response = Promise\wait($router->respond($request));

        $this->assertEquals(Status::OK, $response->getStatus());
        $this->assertSame("ab", Promise\wait(new Message($response->getBody())));
    }

    public function testMerge() {
        $responder = new CallableResponder(function (Request $req) {
            return new Response($req->getUri()->getPath());
        });

        $routerA = new Router;
        $routerA->prefix("a");
        $routerA->addRoute("GET", "{name}", $responder);

        $routerB = new Router;
        $routerB->prefix("b");
        $routerB->addRoute("GET", "{name}", $responder);

        $routerA->merge($routerB);

        Promise\wait($routerA->onStart($this->mockServer()));

        /** @var \Amp\Http\Server\Response $response */
        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/a/bob"));
        $response = Promise\wait($routerA->respond($request));
        $this->assertEquals(Status::OK, $response->getStatus());

        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/a/b/bob"));
        $response = Promise\wait($routerA->respond($request));
        $this->assertEquals(Status::OK, $response->getStatus());

        $request = new Request($this->createMock(Client::class), "GET", Uri\Http::createFromString("/b/bob"));
        $response = Promise\wait($routerA->respond($request));
        $this->assertEquals(Status::NOT_FOUND, $response->getStatus());
    }
}
