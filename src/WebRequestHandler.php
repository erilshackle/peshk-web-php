<?php

namespace Peshk\Web;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;

class WebRequestHandler
{
    protected string $webPath;
    protected array $middlewares = [];
    protected array $routeMiddlewareRules = [];
    protected Psr17Factory $factory;

    public function __construct(string $webPath)
    {
        $this->webPath = rtrim($webPath, '/');
        $this->factory = new Psr17Factory();
    }

    /*--------------------------------------------------------------------
     | Middleware registration
     --------------------------------------------------------------------*/
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

    /*--------------------------------------------------------------------
     | Handle request
     --------------------------------------------------------------------*/
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $uriPath = trim($request->getUri()->getPath(), '/');
        $parts = $uriPath === '' ? [] : explode('/', $uriPath);
        $basePath = $this->resolveBasePath($uriPath);

        $routeInfo = $this->scanDirectory($basePath, $parts);
        $middlewares = $this->collectMiddlewares($uriPath, $routeInfo);

        $finalHandler = function (ServerRequestInterface $req) use ($routeInfo) {
            [$file, $params] = $routeInfo ?? [null, []];
            foreach ($params as $k => $v) {
                $req = $req->withAttribute($k, $v);
            }

            return $this->execute($file, $req);
        };

        $pipeline = new MiddlewarePipeline($middlewares, $finalHandler);

        try {
            return $pipeline->handle($request);
        } catch (\Throwable $e) {
            return $this->factory->createResponse(500)
                ->withHeader('Content-Type', 'text/html')
                ->withBody($this->factory->createStream(
                    "<h1>500 - Internal Server Error</h1><pre>{$e->getMessage()}</pre>"
                ));
        }
    }

    /*--------------------------------------------------------------------
     | Base path resolver (web/pages ou api)
     --------------------------------------------------------------------*/
    protected function resolveBasePath(string $uriPath): string
    {
        return str_starts_with($uriPath, 'api/')
            ? $this->webPath . '/api'
            : $this->webPath . '/pages';
    }

    /*--------------------------------------------------------------------
     | Middleware collection
     --------------------------------------------------------------------*/
    protected function collectMiddlewares(string $path, ?array $routeInfo): array
    {
        $wild = $this->matchWildcardMiddlewares($path);
        $local = $routeInfo ? $this->loadLocalMiddlewares($routeInfo[0]) : [];
        $all = array_merge($this->middlewares, $wild, $local);

        return array_map(fn($m) => $m instanceof MiddlewareInterface ? $m : new $m(), $all);
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
        while (str_starts_with($dir, $this->webPath)) {
            $midFile = "$dir/@middlewares.php";
            if (file_exists($midFile)) {
                $list = include $midFile;
                if (is_array($list)) $result = array_merge($result, $list);
            }
            if ($dir === $this->webPath) break;
            $dir = dirname($dir);
        }
        return array_reverse($result);
    }

    /*--------------------------------------------------------------------
     | Directory scanning + dynamic segments
     --------------------------------------------------------------------*/
    protected function scanDirectory(
        string $dir,
        array $parts,
        int $depth = 0,
        array $params = []
    ): ?array {

        if (!is_dir($dir)) {
            return null;
        }

        // Se terminou os segmentos → procurar index.php
        if (!isset($parts[$depth])) {
            $index = $dir . '/index.php';
            return file_exists($index)
                ? [$index, $params]
                : null;
        }

        $segment = $parts[$depth];

        $entries = scandir($dir);

        $staticDir = null;
        $dynamicDir = null;

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $fullPath = "$dir/$entry";

            if (!is_dir($fullPath)) {
                continue;
            }

            // Diretório estático
            if ($entry === $segment) {
                $staticDir = $fullPath;
            }

            // Diretório dinâmico [param]
            elseif ($this->isDynamicSegment($entry)) {

                if ($dynamicDir) {
                    throw new \RuntimeException(
                        "Ambiguous dynamic directories in $dir: $dynamicDir, $entry"
                    );
                }

                $dynamicDir = $fullPath;
            }
        }

        // 1️⃣ Prioridade: diretório estático
        if ($staticDir) {
            return $this->scanDirectory(
                $staticDir,
                $parts,
                $depth + 1,
                $params
            );
        }

        // 2️⃣ Diretório dinâmico
        if ($dynamicDir) {
            $paramName = trim(basename($dynamicDir), '[]');
            $params[$paramName] = $segment;

            return $this->scanDirectory(
                $dynamicDir,
                $parts,
                $depth + 1,
                $params
            );
        }

        return null;
    }

    protected function isDynamicSegment(string $name): bool
    {
        return preg_match('/^\[.+\]$/', $name);
    }

    /*--------------------------------------------------------------------
     | Execute file: decide web ou API
     --------------------------------------------------------------------*/
    protected function execute(?string $file, ServerRequestInterface $request): ResponseInterface
    {
        $uriPath = $request->getUri()->getPath();
        if (str_contains($uriPath, '/api/'))
            return $this->renderApi($file, $request);
        return $this->renderPage($file, $request);
    }

    /*--------------------------------------------------------------------
     | Render API
     --------------------------------------------------------------------*/
    protected function renderApi(?string $file, ServerRequestInterface $request): ResponseInterface
    {
        $method = strtolower($request->getMethod());

        if (!$file || !file_exists($file)) {
            return $this->factory->createResponse(404)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->factory->createStream(json_encode(['error' => 'File not found'])));
        }
        include_once $file;

        if (function_exists($method)) {
            $ret = $method($request);
        } else {
            return $this->factory->createResponse(405)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->factory->createStream(json_encode(['error' => 'Method not allowed'])));
        }

        return $this->handleApiResponse('', $ret);
    }

    protected function handleApiResponse(string $content, mixed $ret): ResponseInterface
    {
        $body = is_string($content) ? $content : json_encode($ret);
        return $this->factory->createResponse(200)
            ->withHeader("Content-Type", "application/json")
            ->withBody($this->factory->createStream($body));
    }

    /*--------------------------------------------------------------------
     | Render page web + layouts + 404 hierárquico
     --------------------------------------------------------------------*/
    protected function renderPage(?string $file, ServerRequestInterface $request): ResponseInterface
    {
        if (!$file || !file_exists($file)) {
            $file404 = $this->find404File($request);
            if ($file404) return $this->renderPage($file404, $request);
            return $this->factory->createResponse(404)
                ->withHeader("Content-Type", "text/html")
                ->withBody($this->factory->createStream("404 - Página não encontrada"));
        }

        ob_start();
        $ret = include $file;
        $content = ob_get_clean();

        if ($ret instanceof ResponseInterface) return $ret;

        return $this->handlePageResponse($content, $file, $request);
    }

    protected function handlePageResponse(string $content, string $file, ServerRequestInterface $request): ResponseInterface
    {
        $layouts = $this->loadLayouts($file);
        $renderer = new PageRenderer($content, $layouts, $request);
        $final = $renderer->render();

        return $this->factory->createResponse(200)
            ->withHeader("Content-Type", "text/html")
            ->withBody($this->factory->createStream($final));
    }

    protected function find404File(ServerRequestInterface $request): ?string
    {
        $uriPath = trim($request->getUri()->getPath(), '/');
        $basePath = $this->resolveBasePath($uriPath);
        $segments = explode('/', $uriPath);
        $dir = $basePath;

        for ($i = 0; $i <= count($segments); $i++) {
            $candidate = $dir . '/@404.php';
            if (file_exists($candidate)) return $candidate;
            $dir = dirname($dir);
            if ($dir === rtrim($this->webPath, '/')) break;
        }

        return null;
    }

    protected function loadLayouts(string $file): array
    {
        $layouts = [];
        $dir = dirname($file);
        while (str_starts_with($dir, $this->webPath)) {
            $layoutFile = "$dir/@layout.php";
            if (file_exists($layoutFile)) $layouts[] = $layoutFile;
            if ($dir === $this->webPath) break;
            $dir = dirname($dir);
        }
        return array_reverse($layouts);
    }
}
