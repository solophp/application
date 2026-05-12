# Solo Application

[![Latest Version on Packagist](https://img.shields.io/packagist/v/solophp/application.svg)](https://packagist.org/packages/solophp/application)
[![License](https://img.shields.io/packagist/l/solophp/application.svg)](https://github.com/solophp/application/blob/main/LICENSE)
[![PHP Version](https://img.shields.io/packagist/php-v/solophp/application.svg)](https://packagist.org/packages/solophp/application)
[![Coverage](https://img.shields.io/badge/coverage-95%25-brightgreen.svg)](https://github.com/solophp/application)

Application bootstrap with PSR-15 middleware pipeline.

A lightweight, PSR-compliant application kernel that wires together your container, router, middleware, and HTTP layer with zero framework lock-in.

## Features

- PSR-15 middleware pipeline with lazy resolution from the container
- PSR-11 container integration with writable extension
- Service providers for dependency registration
- Three handler forms: controller methods, invokable classes, callables
- Built only on PSR interfaces and `solophp/contracts`

## Installation

```bash
composer require solophp/application
```

## Requirements

- PHP 8.2+
- A PSR-7/PSR-17 implementation (e.g., [nyholm/psr7](https://github.com/nyholm/psr7))
- A container implementing `Solo\Contracts\Container\WritableContainerInterface`
- An HTTP emitter implementing `Solo\Contracts\Http\EmitterInterface`
- A router implementing `Solo\Contracts\Router\RouterInterface` (e.g., [solophp/router](https://github.com/solophp/router))

## Dependencies

| Dependency | Purpose |
|------------|---------|
| psr/container | Container interface |
| psr/http-message | HTTP messages |
| psr/http-server-handler | Request handler |
| psr/http-server-middleware | Middleware |
| psr/http-factory | Response factory |
| solophp/contracts | `WritableContainerInterface`, `EmitterInterface`, `RouterInterface` |

## Quick Start

```php
// public/index.php
<?php

require __DIR__ . '/../vendor/autoload.php';

use Solo\Application\Application;
use Solo\Application\Config;

$basePath = dirname(__DIR__);

$config = new Config(
    basePath: $basePath,
    routesPath: $basePath . '/app/routes.php',
    providers: require $basePath . '/app/providers.php',
    middleware: require $basePath . '/app/middleware.php',
);

$app = new Application($config);
$app->run();
```

## How It Works

`Application` separates *bootstrap* (one-time, in the constructor) from *run* (per request):

```
new Application($config)
      │
      ├── 1. Create / accept container
      ├── 2. Register core services (Container, RouteDispatcher)
      ├── 3. Run providers          ← your dependencies
      └── 4. Load routes            ← require $config->routesPath

$app->run($request?)
      │
      ▼
ServerRequest (provided or resolved from container)
      │
      ▼
MiddlewarePipeline ── [mw 1] → [mw 2] → … → [RoutingMiddleware]
      │                                              │
      │                                              ▼
      │                       request->withAttribute('handler', …)
      │                       request->withAttribute('params',  …)
      ▼
RouteDispatcher  →  Controller / invokable / callable
      │
      ▼
ResponseInterface  →  Emitter::emit()
```

`RouteDispatcher` reads two request attributes:

| Attribute | Type | Source |
|-----------|------|--------|
| `handler` | `array{class-string,string} \| class-string \| callable` | written by your routing middleware |
| `params` | `array<string,mixed>` | written by your routing middleware |

If `handler` is `null`, the dispatcher returns a `404` response from the response factory.

## Configuration

`Config` is an immutable DTO:

```php
new Config(
    basePath:   '/var/www/app',
    routesPath: '/var/www/app/routes.php',
    providers:  [HttpServiceProvider::class, DatabaseServiceProvider::class],
    middleware: [ErrorHandler::class, JsonParser::class, RoutingMiddleware::class],
);
```

### `routes.php`

Returns a closure that receives the router:

```php
<?php

use Solo\Contracts\Router\RouterInterface;

return function (RouterInterface $r): void {
    $r->addRoute('GET',  '/api/users',      [UserController::class, 'index']);
    $r->addRoute('POST', '/api/users',      [UserController::class, 'store']);
    $r->addRoute('GET',  '/api/users/{id}', [UserController::class, 'show']);
};
```

### `providers.php`

```php
<?php

return [
    HttpServiceProvider::class,
    DatabaseServiceProvider::class,
    LoggingServiceProvider::class,
];
```

### `middleware.php`

Order matters — middleware execute top-to-bottom on the way in, bottom-to-top on the way out:

```php
<?php

return [
    ErrorHandlerMiddleware::class,
    CorsMiddleware::class,
    JsonParserMiddleware::class,
    RoutingMiddleware::class, // must be last — writes `handler` / `params`
];
```

## Required Services

Your providers must register these into the container:

| Service ID | Interface |
|-----------|-----------|
| `Psr\Http\Message\ResponseFactoryInterface` | PSR-17 response factory |
| `Psr\Http\Message\ServerRequestInterface` | The incoming request (used when `run()` is called without an argument) |
| `Solo\Contracts\Router\RouterInterface` | Router; `routes.php` is called with this |
| `Solo\Contracts\Http\EmitterInterface` | Sends the response back to the client |

Any middleware or controller class IDs referenced from configuration must also resolve.

## Service Providers

Providers are plain classes with a `register()` method. They are instantiated once during bootstrap:

```php
<?php

namespace App\Providers;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Solo\Container\Container;
use Solo\Contracts\Http\EmitterInterface;
use Solo\Contracts\Router\RouterInterface;
use Solo\HttpEmitter\Emitter;
use Solo\Router\RouteCollector;

final class HttpServiceProvider
{
    public function register(Container $container): void
    {
        $container->set(Psr17Factory::class, fn() => new Psr17Factory());

        $container->set(
            ResponseFactoryInterface::class,
            fn(ContainerInterface $c) => $c->get(Psr17Factory::class),
        );

        $container->set(ServerRequestInterface::class, function (ContainerInterface $c) {
            $factory = $c->get(Psr17Factory::class);
            return (new ServerRequestCreator($factory, $factory, $factory, $factory))
                ->fromGlobals();
        });

        $container->set(RouterInterface::class, fn() => new RouteCollector());
        $container->set(EmitterInterface::class, fn() => new Emitter());
    }
}
```

## Middleware

Middleware are standard PSR-15 (`Psr\Http\Server\MiddlewareInterface`). They are registered by class-string in `middleware.php` and resolved from the container *lazily* — only invoked middleware are instantiated.

```php
<?php

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class JsonParserMiddleware implements MiddlewareInterface
{
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        if ($request->getHeaderLine('Content-Type') === 'application/json') {
            $body = (string) $request->getBody();
            $request = $request->withParsedBody(json_decode($body, true) ?? []);
        }

        return $handler->handle($request);
    }
}
```

You can also push pre-instantiated `MiddlewareInterface` objects directly:

```php
$config = new Config(
    /* … */,
    middleware: [new SomeMiddleware($dependency), AuthMiddleware::class],
);
```

## Routing Middleware

The dispatcher reads `handler` and `params` from request attributes — your last middleware in the chain is responsible for setting them. A minimal version that uses `solophp/router`:

```php
<?php

namespace App\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Solo\Contracts\Router\RouterInterface;

final class RoutingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly RouterInterface $router,
        private readonly ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $match = $this->router->match(
            $request->getMethod(),
            $request->getUri()->getPath(),
        );

        if ($match === false) {
            return $this->responseFactory->createResponse(404);
        }

        $request = $request
            ->withAttribute('handler', $match['handler'])
            ->withAttribute('params', $match['params'] ?? []);

        // If your router attaches route-level middleware, push them onto the pipeline
        // here before calling $handler->handle($request).

        return $handler->handle($request);
    }
}
```

## Route Handlers

`RouteDispatcher` accepts three handler forms. Each receives `(ServerRequestInterface $request, ResponseInterface $response, array $params)`.

### Controller method

The classic `[Class::class, 'method']` form. The class is resolved from the container — constructor dependencies are injected by your DI:

```php
final class UserController
{
    public function __construct(private readonly UserRepository $users) {}

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $params): ResponseInterface
    {
        $user = $this->users->find($params['id']);
        $response->getBody()->write(json_encode($user));
        return $response->withHeader('Content-Type', 'application/json');
    }
}

// routes.php
$r->addRoute('GET', '/users/{id}', [UserController::class, 'show']);
```

### Invokable class

A class-string. The container resolves it and the dispatcher calls `__invoke()`:

```php
final class HealthCheck
{
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $params): ResponseInterface
    {
        $response->getBody()->write('ok');
        return $response;
    }
}

$r->addRoute('GET', '/health', HealthCheck::class);
```

### Callable

Any closure or callable — useful for trivial routes and tests:

```php
$r->addRoute('GET', '/ping', function ($request, $response, $params) {
    $response->getBody()->write('pong');
    return $response;
});
```

## 404 Handling

If your routing middleware doesn't set `handler` on the request, `RouteDispatcher` returns an empty `404` response. To customize, return your own response from the routing middleware instead, or wrap the dispatcher with an error-handling middleware that inspects the status code.

## Custom Container

By default the application creates a `Solo\Container\Container` instance. You can supply your own:

```php
$container = new MyContainer();
// pre-register services if needed
$app = new Application($config, $container);
```

The container must implement `Solo\Contracts\Container\WritableContainerInterface` (`get()` + `set()`).

## Components

| Class | Description |
|-------|-------------|
| `Application` | Bootstrap and run the application |
| `Config` | Immutable configuration DTO |
| `MiddlewarePipeline` | PSR-15 middleware pipeline (acts as both pipeline and `RequestHandlerInterface`) |
| `RouteDispatcher` | Resolves and invokes the matched route handler |

## Testing

The library has no global state — tests can construct an `Application` with any `WritableContainerInterface`. Example:

```php
use Solo\Application\Application;
use Solo\Application\Config;
use Solo\Container\Container;

$container = new Container();
// register mocks for ResponseFactoryInterface, ServerRequestInterface,
// RouterInterface, EmitterInterface here…

$config = new Config(
    basePath:   '/tmp',
    routesPath: '/tmp/routes.php',
    providers:  [],
    middleware: [],
);

$app = new Application($config, $container);
$app->run($request);
```

Run the bundled test suite:

```bash
composer test    # phpunit
composer stan    # phpstan
composer cs      # phpcs (PSR-12)
composer check   # all of the above
```

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
