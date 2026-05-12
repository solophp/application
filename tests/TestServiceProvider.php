<?php

declare(strict_types=1);

namespace Solo\Application\Tests;

use Solo\Contracts\Container\WritableContainerInterface;
use Solo\Contracts\Router\RouterInterface;

final class TestServiceProvider
{
    public function register(WritableContainerInterface $container): void
    {
        $container->set(RouterInterface::class, fn() => new class implements RouterInterface {
            public function addRoute(
                string $method,
                string $path,
                callable|array|string $handler,
                array $options = []
            ): object {
                return $this;
            }

            public function match(string $method, string $uri): array|false
            {
                return false;
            }
        });
    }
}
