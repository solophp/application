<?php

declare(strict_types=1);

namespace Solo\Application;

use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class RouteDispatcher implements RequestHandlerInterface
{
    public function __construct(
        private ContainerInterface $container,
        private ResponseFactoryInterface $responseFactory
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $handler = $request->getAttribute('handler');

        if ($handler === null) {
            return $this->responseFactory->createResponse(404);
        }

        $callable = match (true) {
            is_array($handler) && count($handler) === 2
                => [$this->container->get($handler[0]), $handler[1]],
            is_string($handler)
                => $this->container->get($handler),
            is_callable($handler)
                => $handler,
            default
                => throw new InvalidArgumentException('Invalid route handler'),
        };

        return $callable(
            $request,
            $this->responseFactory->createResponse(),
            $request->getAttribute('params', []),
        );
    }
}
