<?php
require __DIR__ . '/../vendor/autoload.php';

use Peshk\Web\App;

// Configurações da aplicação
$config = [
    'root_path' => __DIR__ . "/../web", // Onde estão /pages e /api
    'debug'     => true,
    // 'context_domain' => 'client_a', // Opcional: para subdomínios/contextos
];

$app = new App($config);

// Opcional: Configurar o Container no Boot
$app->boot(function (App $app) {
    $container = $app->getContainer();

    // Se um middleware precisar de dependências, registre-o aqui
    // $container->add(App\Middlewares\AuthMiddleware::class)
    //           ->addArgument(App\Services\Database::class);
});

// Adicionar Middlewares Globais ou por Rota
// $app->addGlobalMiddleware(App\Middlewares\LogMiddleware::class);
$app->addRouteMiddleware([]);

// Executa tudo!
$app->run();
