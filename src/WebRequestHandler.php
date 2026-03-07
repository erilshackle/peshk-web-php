<?php

namespace Peshk\Web;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use League\Container\Container;

/**
 * Gerenciador de requisições Web baseado em sistema de arquivos.
 * Resolve rotas estáticas, dinâmicas, APIs e arquivos especiais (@layouts, @guards, etc).
 */
class WebRequestHandler
{
    /** @var string Caminho físico base para /pages e /api */
    protected string $webPath;

    /** @var array Middlewares globais aplicados a todas as rotas */
    protected array $middlewares = [];

    /** @var array Regras de middlewares baseadas em padrões de URL */
    protected array $routeMiddlewareRules = [];

    /** @var Container|null Container de dependências para resolver middlewares e controllers */
    protected ?Container $container;

    /** @var Psr17Factory Fábrica de objetos PSR-7 */
    protected Psr17Factory $factory;

    /**
     * @param string $webRoot Caminho absoluto da pasta web (ex: BASE_DIR/web)
     * @param array{
     *  context_domain?: string,  // Nome da subpasta de contexto (subdomínio/projeto)
     *  container?: Container     // Instância do container League/Container
     * } $options
     */
    public function __construct(string $webRoot, array $options = [])
    {
        $root = rtrim($webRoot, '/');

        // Se houver um contexto (ex: um subdomínio), concatena ao caminho root
        if (!empty($options['context_domain'])) {
            $root .= '/' . trim($options['context_domain'], '/');
        }

        $this->webPath = realpath($root) ?: $root;
        $this->container = $options['container'] ?? null;
        $this->factory = new Psr17Factory();
    }

    /**
     * Adiciona um middleware à pilha global.
     * @param MiddlewareInterface|string $middleware Objeto ou nome da classe no container.
     */
    public function addMiddleware(MiddlewareInterface|string $middleware): self
    {
        $this->middlewares[] = $middleware;
        return $this;
    }

    /**
     * Adiciona middlewares para um padrão de rota específico (ex: 'admin/*').
     */
    public function addRouteMiddleware(string $pattern, array $middlewares): self
    {
        $this->routeMiddlewareRules[] = [
            'pattern' => trim($pattern, '/'),
            'middlewares' => $middlewares
        ];
        return $this;
    }

    /**
     * Monta o caminho base físico e limpa os segmentos da URL
     */
    protected function mountPath(ServerRequestInterface $request): array
    {
        $isApi = $this->isApiRoute($request);
        $uri = trim($request->getUri()->getPath(), '/');
        $segments = array_filter(explode('/', $uri));

        // Se for api, removemos o primeiro segmento ('api') para o scan de pastas
        if ($isApi && ($segments[0] ?? '') === 'api') {
            array_shift($segments);
        }

        return [
            'base' => $isApi ? $this->webPath . '/api' : $this->webPath . '/pages',
            'segments' => array_values($segments),
            'uri' => $uri
        ];
    }

    /**
     * Resolve o ciclo de vida da requisição.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // 1. Resolve onde estamos (API ou Pages)
        $mount = $this->mountPath($request);
        $isApi = $this->isApiRoute($request);

        $context = [
            'file' => null,
            'params' => [],
            'layouts' => [],
            'middlewares' => $this->middlewares,
            'error404' => null,
            'controller' => null
        ];

        // 2. Coleta middlewares globais por pattern
        $this->applyRouteMiddlewareRules($mount['uri'], $context);

        // 3. Scanner (Recursão nas pastas)
        $this->scanDirectory($mount['base'], $mount['segments'], $mount['uri'], $context);

        // 4. Handler Final
        $finalHandler = function (ServerRequestInterface $req) use ($context, $isApi) {
            foreach ($context['params'] as $k => $v) {
                $req = $req->withAttribute($k, $v);
            }
            return $this->execute($context['file'], $req, $isApi, $context);
        };

        // 5. Dispara a Pipeline
        $pipeline = new MiddlewarePipeline(
            $context['middlewares'],
            $finalHandler,
            $this->container // Pode ser null ou a instância vinda do App
        );
        return $pipeline->handle($request);
    }

    /**
     * Verifica se a rota é destinada à API.
     */
    public function isApiRoute(ServerRequestInterface $request): bool
    {
        $path = trim($request->getUri()->getPath(), '/');
        return str_starts_with($path, 'api/') || $path === 'api';
    }

    /**
     * Percorre as pastas de forma recursiva buscando a rota e coletando @meta arquivos.
     */
    protected function scanDirectory(string $dir, array $parts, string $fullUri, array &$context): void
    {
        if (!is_dir($dir)) return;

        // Anexa arquivos especiais desta pasta (@guard, @layout, etc)
        $this->attachSpecialFiles($dir, $fullUri, $context);

        // Se não há mais segmentos, tenta o index.php da pasta atual
        if (empty($parts)) {
            $index = $dir . '/index.php';
            if (file_exists($index)) $context['file'] = $index;
            return;
        }

        $segment = array_shift($parts);
        $staticFile = $dir . '/' . $segment . '.php';
        $staticDir  = $dir . '/' . $segment;

        // Verificação de Ambiguidade: não permite arquivo e pasta com mesmo nome/index
        if (file_exists($staticFile) && is_dir($staticDir) && file_exists($staticDir . '/index.php')) {
            throw new \RuntimeException("Ambiguity detected: both $staticFile and $staticDir/index.php exist.");
        }

        // Prioridade 1: Arquivo estático
        if (file_exists($staticFile) && empty($parts)) {
            $context['file'] = $staticFile;
            return;
        }

        // Prioridade 2: Pasta estática (recursão)
        if (is_dir($staticDir)) {
            $this->scanDirectory($staticDir, $parts, $fullUri, $context);
            return;
        }

        // Prioridade 3: Segmento dinâmico [id], [slug], etc.
        $dynamics = $this->findDynamicSegments($dir);
        if ($dynamics) {
            if (count($dynamics) > 1) throw new \RuntimeException("Multiple dynamic routes in $dir");

            $match = $dynamics[0];
            $paramName = trim(str_replace('.php', '', $match), '[]');
            $context['params'][$paramName] = $segment;

            $fullPathMatch = $dir . '/' . $match;
            if (is_dir($fullPathMatch)) {
                $this->scanDirectory($fullPathMatch, $parts, $fullUri, $context);
            } elseif (empty($parts)) {
                $context['file'] = $fullPathMatch;
            }
        }
    }

    /**
     * Coleta metadados de arquivos especiais durante a navegação.
     */
    protected function attachSpecialFiles(string $dir, string $fullUri, array &$context): void
    {
        // @guard.php: se retornar false, interrompe o scan
        if (file_exists("$dir/@guard.php")) {
            $allowed = include "$dir/@guard.php";
            if ($allowed === false) throw new \RuntimeException("Access forbidden by @guard", 403);
        }

        if (file_exists("$dir/@404.php")) $context['error404'] = "$dir/@404.php";
        if (file_exists("$dir/@layout.php")) $context['layouts'][] = "$dir/@layout.php";
        if (file_exists("$dir/@controller.php")) $context['controller'] = "$dir/@controller.php";

        // @middlewares.php: anexa middlewares locais da pasta
        if (file_exists("$dir/@middlewares.php")) {
            $local = include "$dir/@middlewares.php";
            if (is_array($local)) {
                $context['middlewares'] = array_merge($context['middlewares'], $local);
            }
        }
    }

    /**
     * Aplica middlewares globais registrados por padrão de string.
     */
    protected function applyRouteMiddlewareRules(string $uri, array &$context): void
    {
        foreach ($this->routeMiddlewareRules as $rule) {
            // Transforma 'admin/*' em uma regex válida: '#^admin/.*$#'
            $pattern = preg_quote($rule['pattern'], '#');
            $pattern = str_replace('\*', '.*', $pattern);
            $regex = '#^' . $pattern . '$#';

            if (preg_match($regex, $uri)) {
                $context['middlewares'] = array_merge($context['middlewares'], (array)$rule['middlewares']);
            }
        }
    }

    /**
     * Executa o arquivo final da rota (API ou Página).
     */
    protected function execute(?string $file, ServerRequestInterface $req, bool $isApi, array $context): ResponseInterface
    {
        if (!$file) {
            if ($isApi) return $this->createErrorResponse("Not Found", 404, true);
            if ($context['error404']) return $this->renderPage($context['error404'], $req, $context)->withStatus(404);
            return $this->createErrorResponse("404 - Not Found", 404, false);
        }

        return $isApi ? $this->renderApi($file, $req) : $this->renderPage($file, $req, $context);
    }

    /**
     * Renderiza resposta de API baseada em métodos HTTP (get, post...).
     */
    protected function renderApi(string $file, ServerRequestInterface $request): ResponseInterface
    {
        $method = strtolower($request->getMethod());
        include_once $file;
        if (!function_exists($method)) return $this->createErrorResponse("Method not allowed", 405, true);

        $result = $method($request);
        if ($result instanceof ResponseInterface) return $result;

        $isJson = is_array($result) || is_object($result);
        return $this->factory->createResponse(200)
            ->withHeader("Content-Type", $isJson ? "application/json" : "text/plain")
            ->withBody($this->factory->createStream($isJson ? json_encode($result) : (string)$result));
    }

    /**
     * Renderiza página HTML processando @controller e @layouts.
     */
    protected function renderPage(string $file, ServerRequestInterface $request, array $context): ResponseInterface
    {
        $data = [];

        // Processamento do Controller
        if ($context['controller']) {
            include_once $context['controller'];
            $httpMethod = strtolower($request->getMethod());
            if (function_exists($httpMethod)) {
                $res = $httpMethod($request);
                if ($res instanceof ResponseInterface) return $res;
                if (is_array($res)) $data = $res;
            }
        }

        extract($data);

        ob_start();
        $output = include $file;
        $content = ob_get_clean();

        if ($output instanceof ResponseInterface) return $output;

        $renderer = new PageRenderer($content, $context['layouts'], $request);
        return $this->factory->createResponse(200)
            ->withHeader("Content-Type", "text/html")
            ->withBody($this->factory->createStream($renderer->render()));
    }

    /**
     * Localiza padrões [nome] no diretório.
     */
    protected function findDynamicSegments(string $dir): array
    {
        return array_values(array_filter(scandir($dir), function ($entry) {
            return preg_match('/^\[[a-zA-Z_][a-zA-Z0-9_]*\](\.php)?$/', $entry);
        }));
    }

    /**
     * Cria resposta básica de erro.
     */
    protected function createErrorResponse(string $msg, int $code, bool $isApi): ResponseInterface
    {
        $res = $this->factory->createResponse($code);
        return $isApi
            ? $res->withHeader('Content-Type', 'application/json')->withBody($this->factory->createStream(json_encode(['error' => $msg])))
            : $res->withHeader('Content-Type', 'text/html')->withBody($this->factory->createStream("<h1>$code</h1><p>$msg</p>"));
    }
}
