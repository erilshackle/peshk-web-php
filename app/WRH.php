<?php

namespace Peshk\Web;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;

class WRH
{
    protected string $rootPath;
    protected array $middlewares = [];
    protected array $routeMiddlewareRules = [];
    protected Psr17Factory $factory;

    public function __construct(string $rootPath)
    {
        $this->rootPath = rtrim($rootPath, '/');
        $this->factory = new Psr17Factory();
    }

    /*
    |--------------------------------------------------------------------------
    | Middleware Registration
    |--------------------------------------------------------------------------
    */

    public function addGlobalMiddleware(MiddlewareInterface|string $middleware): self
    {
        $this->middlewares[] = $middleware;
        return $this;
    }

    public function addRouteMiddlewares(string $pattern, array $middlewares): self
    {
        $this->routeMiddlewareRules[] = [
            'pattern' => trim($pattern, '/'),
            'middlewares' => $middlewares
        ];
        return $this;
    }

    /*
    |--------------------------------------------------------------------------
    | Request Handling
    |--------------------------------------------------------------------------
    */

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $uriPath = trim($request->getUri()->getPath(), '/');
        $uriPath = $uriPath ?: 'index';

        $basePath = $this->resolveBasePath($uriPath);

        $routeInfo = $this->scanDirectory(
            $basePath,
            explode('/', $uriPath)
        );

        $middlewares = $this->collectMiddlewares($uriPath, $routeInfo);

        $finalHandler = function (ServerRequestInterface $req) use ($routeInfo) {
            [$file, $params] = $routeInfo ?? [null, []];

            foreach ($params as $k => $v) {
                $req = $req->withAttribute($k, $v);
            }

            return $this->execute($file, $req);
        };

        $pipeline = new MiddlewarePipeline($middlewares, $finalHandler);

        return $pipeline->handle($request);
    }

    /*
    |--------------------------------------------------------------------------
    | Base Path Resolver
    |--------------------------------------------------------------------------
    */

    protected function resolveBasePath(string $uriPath): string
    {
        if (str_starts_with($uriPath, 'api/')) {
            return $this->rootPath . '/api';
        }

        return $this->rootPath . '/pages';
    }

    /*
    |--------------------------------------------------------------------------
    | Middleware Collector
    |--------------------------------------------------------------------------
    */

    protected function collectMiddlewares(string $path, ?array $routeInfo): array
    {
        $wild = $this->matchWildcardMiddlewares($path);
        $local = $routeInfo ? $this->loadLocalMiddlewares($routeInfo[0]) : [];

        $all = array_merge(
            $this->middlewares,
            $wild,
            $local
        );

        return array_map(function ($middleware) {
            if ($middleware instanceof MiddlewareInterface) {
                return $middleware;
            }

            return new $middleware();
        }, $all);
    }

    protected function matchWildcardMiddlewares(string $path): array
    {
        $out = [];

        foreach ($this->routeMiddlewareRules as $rule) {
            $pattern = "#^" . str_replace('*', '.*', $rule['pattern']) . "$#";

            if (preg_match($pattern, $path)) {
                $out = array_merge($out, $rule['middlewares']);
            }
        }

        return $out;
    }

    protected function loadLocalMiddlewares(string $filePath): array
    {
        $result = [];
        $dir = dirname($filePath);

        while (str_starts_with($dir, $this->rootPath)) {

            $midFile = "$dir/@middlewares.php";

            if (file_exists($midFile)) {
                $list = include $midFile;
                if (is_array($list)) {
                    $result = array_merge($result, $list);
                }
            }

            if ($dir === $this->rootPath) break;

            $dir = dirname($dir);
        }

        return array_reverse($result);
    }

    /*
    |--------------------------------------------------------------------------
    | Directory Scanner
    |--------------------------------------------------------------------------
    */

    protected function scanDirectory(string $dir, array $parts, int $depth = 0, array $params = []): ?array
    {
        if (!is_dir($dir)) return null;

        $entries = scandir($dir);

        $staticFile  = null;
        $indexFile   = null;
        $dynamicFile = null;
        $dynamicDir  = null;

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;

            $full = "$dir/$entry";

            // ======== ARQUIVOS =========
            if (is_file($full)) {
                $name = pathinfo($entry, PATHINFO_FILENAME);

                if ($name === ($parts[$depth] ?? null)) {
                    $staticFile = $full;
                } elseif ($this->isDynamicSegment($name)) {
                    if ($dynamicFile) {
                        throw new \RuntimeException("Ambiguous dynamic files in $dir: $dynamicFile, $entry");
                    }
                    $dynamicFile = $full;
                }
            }

            // ======== DIRETÓRIOS =========
            if (is_dir($full)) {
                if ($entry === ($parts[$depth] ?? null)) {
                    // Diretório estático
                    $indexCandidate = "$full/index.php";
                    if (file_exists($indexCandidate)) {
                        $indexFile = $indexCandidate;
                    }
                } elseif ($this->isDynamicSegment($entry)) {
                    if ($dynamicDir) {
                        throw new \RuntimeException("Ambiguous dynamic directories in $dir: $dynamicDir, $entry");
                    }
                    $dynamicDir = $full;
                }
            }
        }

        // ======== ESCOLHER PRIORIDADE =========
        // 1. Arquivo estático
        if ($staticFile) return [$staticFile, $params];

        // 2. Diretório index.php
        if ($indexFile) return [$indexFile, $params];

        // 3. Diretório dinâmico
        if ($dynamicDir) {
            $paramName = trim(basename($dynamicDir), '[]');
            $newParams = $params;
            $newParams[$paramName] = $parts[$depth] ?? null;
            return $this->scanDirectory($dynamicDir, $parts, $depth + 1, $newParams);
        }

        // 4. Arquivo dinâmico
        if ($dynamicFile) {
            $paramName = trim(pathinfo($dynamicFile, PATHINFO_FILENAME), '[]');
            $newParams = $params;
            $newParams[$paramName] = $parts[$depth] ?? null;
            return [$dynamicFile, $newParams];
        }

        return null;
    }

    protected function isDynamicSegment(string $name): bool
    {
        return preg_match('/^\[.+\]$/', $name);
    }

    protected function execute(string $file, ServerRequestInterface $request): ResponseInterface
    {
        // Determina se é API ou página web
        if (str_contains($file, '/api/')) {
            return $this->renderApi($file, $request);
        }

        return $this->renderPage($file, $request);
    }

    /**
     * Renderiza uma página web com layouts, ou 404 hierárquico
     */
    protected function renderPage(?string $file, ServerRequestInterface $request): ResponseInterface
    {
        if (!$file || !file_exists($file)) {
            // Tenta encontrar @404 mais específico
            $file404 = $this->find404File($request);
            if ($file404) {
                return $this->renderPage($file404, $request); // renderiza como página normal
            }

            // fallback genérico
            return $this->factory->createResponse(404)
                ->withHeader("Content-Type", "text/html")
                ->withBody($this->factory->createStream("404 - Página não encontrada"));
        }

        ob_start();
        $ret = include $file; // o include pode retornar ResponseInterface ou conteúdo
        $content = ob_get_clean();

        if ($ret instanceof ResponseInterface) {
            return $ret;
        }

        return $this->handlePageResponse($content, $file, $request);
    }

    /**
     * Renderiza API via função get/post/put/delete
     */
    protected function renderApi(string $file, ServerRequestInterface $request): ResponseInterface
    {
        $method = strtolower($request->getMethod()); // get, post, put, delete
        include_once $file;

        $ret = null;

        if (function_exists($method)) {
            $ret = $method($request);
        } else {
            return $this->factory->createResponse(405)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->factory->createStream(json_encode([
                    'error' => 'Method not allowed'
                ])));
        }

        return $this->handleApiResponse($ret);
    }

    /**
     * Procura o @404.php mais específico para o path atual
     */
    protected function find404File(ServerRequestInterface $request): ?string
    {
        $uriPath = trim($request->getUri()->getPath(), '/');
        $basePath = $this->resolveBasePath($uriPath);
        $segments = explode('/', $uriPath);
        $dir = $basePath;

        // Itera da pasta mais profunda até a raiz
        for ($i = count($segments); $i >= 0; $i--) {
            $candidate = $dir . '/@404.php';
            if (file_exists($candidate)) {
                return $candidate;
            }
            $dir = dirname($dir);
            if ($dir === rtrim($this->rootPath, '/')) break;
        }

        return null;
    }

    /**
     * Resposta para API (JSON)
     */
    protected function handleApiResponse(mixed $ret): ResponseInterface
    {
        $body = is_string($ret) ? $ret : json_encode($ret);

        return $this->factory->createResponse(200)
            ->withHeader("Content-Type", "application/json")
            ->withBody($this->factory->createStream($body));
    }

    /**
     * Resposta para páginas web com layouts
     */
    protected function handlePageResponse(string $content, string $file, ServerRequestInterface $request): ResponseInterface
    {
        $layouts = $this->loadLayouts($file);

        $renderer = new PageRenderer($content, $layouts, $request);
        $final = $renderer->render();

        return $this->factory->createResponse(200)
            ->withHeader("Content-Type", "text/html")
            ->withBody($this->factory->createStream($final));
    }

    /**
     * Carrega layouts hierárquicos
     */
    protected function loadLayouts(string $file): array
    {
        $layouts = [];
        $dir = dirname($file);

        while (str_starts_with($dir, $this->rootPath)) {
            $layoutFile = "$dir/@layout.php";
            if (file_exists($layoutFile)) $layouts[] = $layoutFile;

            if ($dir === $this->rootPath) break;
            $dir = dirname($dir);
        }

        return array_reverse($layouts);
    }
}
