<?php

namespace Peshk\Web;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use League\Container\Container;

class MiddlewarePipeline implements RequestHandlerInterface
{
    protected array $middlewares;
    protected \Closure $finalHandler;
    protected ?Container $container;
    protected int $index = 0;

    public function __construct(array $middlewares, \Closure $finalHandler, ?Container $container = null)
    {
        $this->middlewares = array_values($middlewares);
        $this->finalHandler = $finalHandler;
        $this->container = $container;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!isset($this->middlewares[$this->index])) {
            return ($this->finalHandler)($request);
        }

        $entry = $this->middlewares[$this->index];
        $this->index++;

        $middleware = $this->resolve($entry);
        return $middleware->process($request, $this);
    }

    protected function resolve($entry): MiddlewareInterface
    {
        if ($entry instanceof MiddlewareInterface) return $entry;

        if (is_string($entry)) {
            // Lazy Loading via Container (se existir)
            if ($this->container && $this->container->has($entry)) {
                $instance = $this->container->get($entry);
            } else {
                // Instanciação direta (Standard)
                $instance = new $entry();
            }

            if ($instance instanceof MiddlewareInterface) return $instance;
        }

        throw new \RuntimeException("Falha ao resolver middleware: " . (is_string($entry) ? $entry : gettype($entry)));
    }
}