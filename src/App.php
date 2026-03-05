<?php

namespace Peshk\Web;

use League\Container\Container;
use League\Container\ReflectionContainer;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;

class App
{
    protected Container $container;
    protected WebRequestHandler $router;
    protected ?\Closure $bootCallable = null;

    /**
     * Summary of __construct
     * @param array{
     *     root_path?: string,
     *     timezone?: string
     * } $config
     */
    public function __construct(array $config = [])
    {
        $this->container = new Container();

        // Auto-wiring
        $this->container->delegate(
            new ReflectionContainer(true)
        );

        $this->container->add('config', $config);

        $this->container->add(Psr17Factory::class)
            ->setShared(true);

        $rootPath = $config['root_path'] ?? __DIR__ . '/web';


        // Router NÃO depende do container
        $this->router = new WebRequestHandler($rootPath);
    }

    public function boot(callable $callback): self
    {
        $this->bootCallable = \Closure::fromCallable($callback);
        return $this;
    }

    public function getContainer(): Container
    {
        return $this->container;
    }

    public function getRouter(): WebRequestHandler
    {
        return $this->router;
    }

    /*
    |--------------------------------------------------------------------------
    | Middleware Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Adiciona middleware global
     */
    public function addMiddleware(string $middlewareClass): self
    {
        $instance = $this->resolveMiddleware($middlewareClass);
        $this->router->addGlobalMiddleware($instance);

        return $this;
    }

    /**
     * Adiciona múltiplos middlewares globais
     */
    public function addMiddlewares(array $middlewares): self
    {
        foreach ($middlewares as $middlewareClass) {
            $this->addMiddleware($middlewareClass);
        }

        return $this;
    }

    /**
     * Adiciona middleware por padrão de rota (wildcard)
     */
    public function addRouteMiddleware(string $pattern, array $middlewares): self
    {
        $instances = [];

        foreach ($middlewares as $middlewareClass) {
            $instances[] = $this->resolveMiddleware($middlewareClass);
        }

        $this->router->addRouteMiddlewares($pattern, $instances);

        return $this;
    }

    /**
     * Resolve middleware via container
     */
    protected function resolveMiddleware(string $middlewareClass): MiddlewareInterface
    {
        $instance = $this->container->get($middlewareClass);

        if (!$instance instanceof MiddlewareInterface) {
            throw new \RuntimeException(
                "Middleware {$middlewareClass} must implement MiddlewareInterface"
            );
        }

        return $instance;
    }

    /*
    |--------------------------------------------------------------------------
    | Run Application
    |--------------------------------------------------------------------------
    */

    public function run(): void
    {
        if ($this->bootCallable) {
            ($this->bootCallable)($this);
        }

        $psr17 = $this->container->get(Psr17Factory::class);

        $creator = new ServerRequestCreator(
            $psr17,
            $psr17,
            $psr17,
            $psr17
        );

        $request = $creator->fromGlobals();

        $response = $this->router->handle($request);

        $this->sendResponse($response);
    }

    protected function sendResponse(ResponseInterface $response): void
    {
        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                header("$name: $value", false);
            }
        }

        http_response_code($response->getStatusCode());
        echo $response->getBody();
    }
}
