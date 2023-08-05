# http-server-router

This package provides a routing `RequestHandler` for [Amp's HTTP server](https://github.com/amphp/http-server) based on the request URI and method using [FastRoute](https://github.com/nikic/FastRoute).

## Installation

This package can be installed as a [Composer](https://getcomposer.org/) dependency.

```bash
composer require amphp/http-server-router
```

## Usage

**`Router`** implements `RequestHandler`, which is used by an [`HttpServer`](https://github.com/amphp/http-server#creating-an-http-server) to handle incoming requests. Incoming requests are routed by `Router` to other `RequestHandler`s based on the request path.

Routes can be defined using the `addRoute($method, $uri, $requestHandler, ...$middeware)` method.

```php
public function addRoute(
    string $method,
    string $uri,
    RequestHandler $requestHandler,
    Middleware ...$middlewares,
): void
```

Matched route arguments are available in the request attributes under the `Amp\Http\Server\Router` key as an associative array.

Middleware provided to `addRoute()` will only be applied to the given route.

Please read the [FastRoute documentation on how to define placeholders](https://github.com/nikic/FastRoute#defining-routes).

### Middleware

In addition to specifying middlewares by route with `addRoute()`, you may wrap all routes with a common set of middlware using `stackMiddleware(...$middleware)`.

```php
public function stackMiddleware(Middleware ...$middlewares): void
```

### Fallback

If no routes match a request path, you can specify another instance of `RequestHandler` which will handle any unmatched routes. If no fallback handler is provided, a 404 response will be returned using the instance of `ErrorHandler` provided to the `Router` constructor.

```php
public function setFallback(RequestHandler $requestHandler): void
```

## Example

```php
use Amp\Http\HttpStatus;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Http\Server\SocketHttpServer;

// $logger is an instance of a PSR-3 logger.
$server = SocketHttpServer::createForDirectAccess($logger);
$errorHandler = new DefaultErrorHandler();

$router = new Router($server, $errorHandler);

$router->addRoute('GET', '/', new ClosureRequestHandler(
    function () {
        return new Response(
            status: HttpStatus::OK,
            headers: ['content-type' => 'text/plain'],
            body: 'Hello, world!',
        );
    },
));

$router->addRoute('GET', '/{name}', new ClosureRequestHandler(
    function (Request $request) {
        $args = $request->getAttribute(Router::class);
        return new Response(
            status: HttpStatus::OK,
            headers: ['content-type' => 'text/plain'],
            body: "Hello, {$args['name']}!",
        );
    },
));

$server->expose('0.0.0.0:1337');

$server->start($router, $errorHandler);

// Serve requests until SIGINT or SIGTERM is received by the process.
Amp\trapSignal([SIGINT, SIGTERM]);

$server->stop();
```

A full example is found in [`examples/hello-world.php`](https://github.com/amphp/http-server-router/blob/2.x/examples/hello-world.php).

```bash
php examples/hello-world.php
```

## Limitations

The `Router` will decode the URI path before matching.
This will also decode any forward slashes (`/`), which might result in unexpected matching for URIs with encoded slashes.
FastRoute placeholders match path segments by default, which are separated by slashes.
That means a route like `/token/{token}` won't match if the token contains an encoded slash.
You can work around this limitation by using a custom regular expression for the placeholder like `/token/{token:.+}`.

## Security

If you discover any security related issues, please use the private security issue reporter instead of using the public issue tracker.

## License

The MIT License (MIT). Please see [LICENSE](./LICENSE) for more information.
