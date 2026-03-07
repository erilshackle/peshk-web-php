<?php

namespace App\Middlewares;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Nyholm\Psr7\Response;

class AuthMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // 1. Lógica "Antes" (Ex: Verificar sessão)
        session_start();
        if (!isset($_SESSION['user'])) {
            // Interrompe o fluxo e retorna uma resposta própria
            return new Response(401, [], 'Não autorizado');
        }

        // 2. Passa para o próximo middleware ou para o Controller final
        $response = $handler->handle($request);

        // 3. Lógica "Depois" (Ex: Adicionar um header de segurança)
        return $response->withHeader('X-Auth-Checked', 'true');
    }
}