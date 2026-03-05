<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Peshk\Web\App;

$app = new App([
    'root_path' => __DIR__ . '/../web',
    'timezone' => 'UTC'
]);

// Registrar boot callable
$app->boot(function (App $app) {
    // $container = $app->getContainer();

    // Registrar serviços
    // $container->add('db', function () {
    //     return new PDO('mysql:host=localhost;dbname=test', 'user', 'pass');
    // });
});

// Middlewares
$app->addMiddlewares([
    // \App\Middleware\AuthMiddleware::class,
    // \App\Middleware\LoggingMiddleware::class,
]);

// Executa
$app->run();
