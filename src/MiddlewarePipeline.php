<?php
namespace Peshk\Web;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Nyholm\Psr7\Factory\Psr17Factory;

/**
 * === MiddlewarePipeline ===
 * Executa middlewares encadeados usando closures.
 */
class MiddlewarePipeline
{
    private array $middlewares = [];
    private \Closure $finalHandler;

    public function __construct(array $middlewares, \Closure $finalHandler)
    {
        $this->middlewares = $middlewares;
        $this->finalHandler = $finalHandler;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $index = 0;
        $pipeline = function(ServerRequestInterface $req) use (&$pipeline, &$index): ResponseInterface {
            if (!isset($this->middlewares[$index])) {
                return ($this->finalHandler)($req);
            }
            $middleware = $this->middlewares[$index];
            $index++;
            return $middleware->process($req, $pipeline);
        };

        return $pipeline($request);
    }
}