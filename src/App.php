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
    protected array $config;
    protected ?\Closure $bootCallable = null;

    /**
     * @param array $config Configurações globais (debug, root_path, context_domain, etc.)
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->container = new Container();

        // Ativa auto-wiring para resolver dependências automaticamente
        $this->container->delegate(new ReflectionContainer(true));

        // Registra o container e as configs para uso em Middlewares/Controllers
        $this->container->add('config', $config);
        $this->container->add(Container::class, $this->container)->setShared(true);

        // Factory PSR-17 compartilhada
        $this->container->add(Psr17Factory::class)->setShared(true);

        // Instancia o WRH com a nova assinatura
        $webRoot = realpath($config['root_path'] ?? getcwd() . '/web');
        $this->router = new WebRequestHandler($webRoot, [
            'container' => $this->container,
            'context_domain' => $config['domain'] ?? null
        ]);
    }

    public function getContainer(): Container
    {
        return $this->container;
    }

    /**
     * Define um callback de inicialização para registrar serviços extras ou middlewares.
     */
    public function boot(callable $callback): self
    {
        $this->bootCallable = \Closure::fromCallable($callback);
        return $this;
    }

    /**
     * Atalho para adicionar middlewares globais via App
     */
    public function addGlobalMiddleware(string|MiddlewareInterface ...$middlewares): self
    {
        foreach ($middlewares as $mw) {
            $this->router->addMiddleware($mw);
        }
        return $this;
    }

    /**
     * Atalho para adicionar middlewares por rota via App
     * @param array $rules Ex: ['admin/*' => [AuthMiddleware::class, LogMiddleware::class], 'api/*' => ApiAuthMiddleware::class]
     */
    public function addRouteMiddleware(array $rules): self
    {
        foreach ($rules as $pattern => $middlewares) {
            $this->router->addRouteMiddleware($pattern, $middlewares);
        }
        return $this;
    }

    /**
     * Executa a aplicação
     */
    public function run(): void
    {
        try {
            // Executa o boot se definido
            if ($this->bootCallable) {
                ($this->bootCallable)($this);
            }

            // Cria a Request PSR-7 a partir das globais do PHP
            $psr17 = $this->container->get(Psr17Factory::class);
            $creator = new ServerRequestCreator($psr17, $psr17, $psr17, $psr17);
            $request = $creator->fromGlobals();

            // O Router assume o controle do fluxo
            $response = $this->router->handle($request);

            // Envia a resposta final para o navegador
            $this->send($response);
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * Tratamento de exceções não capturadas (Erro 500)
     */
    protected function handleException(\Throwable $e): void
    {
        $isDebug = $this->config['debug'] ?? false;

        // Em produção, você logaria o erro aqui
        // error_log($e->getMessage());

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
        }

        if ($isDebug) {
            echo "<h2>500 - Internal Server Error</h2>";
            echo "<p><strong>Message:</strong> {$e->getMessage()}</p>";
            echo "<p><strong>File:</strong> {$e->getFile()} (line {$e->getLine()})</p>";
            echo "<pre>{$e->getTraceAsString()}</pre>";
        } else {
            echo "<h1>Algo deu errado.</h1><p>Nossa equipe técnica já foi avisada.</p>";
        }
    }

    /**
     * Emite cabeçalhos e corpo da resposta PSR-7
     */
    protected function send(ResponseInterface $response): void
    {
        if (headers_sent()) return;

        // Status
        header(sprintf(
            'HTTP/%s %s %s',
            $response->getProtocolVersion(),
            $response->getStatusCode(),
            $response->getReasonPhrase()
        ), true, $response->getStatusCode());

        // Headers
        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                header("$name: $value", false);
            }
        }

        // Body
        echo $response->getBody();
    }
}
