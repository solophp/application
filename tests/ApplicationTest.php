<?php

declare(strict_types=1);

namespace Solo\Application\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Solo\Application\Application;
use Solo\Application\Config;
use Solo\Container\Container;
use Solo\Contracts\Container\WritableContainerInterface;
use Solo\Contracts\Http\EmitterInterface;
use Solo\Contracts\Router\RouterInterface;

final class ApplicationTest extends TestCase
{
    private string $routesFile;

    protected function setUp(): void
    {
        $this->routesFile = sys_get_temp_dir() . '/test_routes_' . uniqid() . '.php';
        file_put_contents($this->routesFile, '<?php return function($router) {};');
    }

    protected function tearDown(): void
    {
        @unlink($this->routesFile);
    }

    public function testConstructorSetsConfigInContainer(): void
    {
        $container = $this->createContainer();
        $config = $this->createConfig();

        $app = new Application($config, $container);

        $this->assertSame($config, $app->config);
        $this->assertSame($container, $app->container);
    }

    public function testConstructorUsesDefaultContainer(): void
    {
        $config = $this->createConfig(providers: [TestServiceProvider::class]);

        $app = new Application($config);

        $this->assertInstanceOf(Container::class, $app->container);
    }

    public function testConstructorRegistersProviders(): void
    {
        $container = $this->createContainer();

        $providerFile = sys_get_temp_dir() . '/test_provider_' . uniqid() . '.php';
        $markerFile = sys_get_temp_dir() . '/provider_called_' . uniqid();

        file_put_contents($providerFile, '<?php
            return new class {
                public function register($container): void {
                    file_put_contents("' . $markerFile . '", "1");
                }
            };
        ');

        $provider = require $providerFile;
        $providerClass = get_class($provider);

        $config = $this->createConfig(providers: [$providerClass]);

        new Application($config, $container);

        $this->assertFileExists($markerFile);

        unlink($providerFile);
        unlink($markerFile);
    }

    public function testRunExecutesMiddlewarePipelineAndEmitsResponse(): void
    {
        $request = $this->createRequestWithRoute(fn($req, $res, $params) => $res);

        $emitter = $this->createMock(EmitterInterface::class);
        $emitter->expects($this->once())->method('emit')->with($this->isInstanceOf(ResponseInterface::class));

        $container = $this->createContainer($request, $emitter);

        $app = new Application($this->createConfig(), $container);
        $app->run($request);
    }

    public function testRunUsesRequestFromContainerWhenNotProvided(): void
    {
        $request = $this->createRequestWithRoute(fn($req, $res, $params) => $res);

        $emitter = $this->createMock(EmitterInterface::class);
        $emitter->expects($this->once())->method('emit');

        $container = $this->createContainer($request, $emitter);

        $app = new Application($this->createConfig(), $container);
        $app->run();
    }

    public function testRunWithMiddleware(): void
    {
        $modifiedResponse = $this->createMock(ResponseInterface::class);
        $request = $this->createRequestWithRoute(fn($req, $res, $params) => $res);

        $middleware = $this->createMock(MiddlewareInterface::class);
        $middleware->method('process')->willReturn($modifiedResponse);

        $emitter = $this->createMock(EmitterInterface::class);
        $emitter->expects($this->once())->method('emit')->with($modifiedResponse);

        $container = $this->createContainer($request, $emitter);
        $container->set('TestMiddleware', fn() => $middleware);

        $app = new Application(
            $this->createConfig(middleware: ['TestMiddleware']),
            $container,
        );
        $app->run($request);
    }

    private function createConfig(array $providers = [], array $middleware = []): Config
    {
        return new Config(
            basePath: '/app',
            routesPath: $this->routesFile,
            providers: $providers,
            middleware: $middleware,
        );
    }

    private function createContainer(
        ?ServerRequestInterface $request = null,
        ?EmitterInterface $emitter = null,
    ): WritableContainerInterface {
        $container = new Container();
        $container->set(RouterInterface::class, fn() => $this->createMock(RouterInterface::class));
        $container->set(ResponseFactoryInterface::class, function () {
            $factory = $this->createMock(ResponseFactoryInterface::class);
            $factory->method('createResponse')->willReturn($this->createMock(ResponseInterface::class));
            return $factory;
        });
        if ($request !== null) {
            $container->set(ServerRequestInterface::class, fn() => $request);
        }
        if ($emitter !== null) {
            $container->set(EmitterInterface::class, fn() => $emitter);
        }
        return $container;
    }

    private function createRequestWithRoute(callable $handler, array $params = []): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturnMap([
            ['handler', null, $handler],
            ['params', [], $params],
        ]);
        return $request;
    }
}
