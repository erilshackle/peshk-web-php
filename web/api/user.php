<?php

return [
    'name' => 'User API',
    'version' => '1.0',
    'endpoints' => [
        [
            'method' => 'GET',
            'path' => '/users',
            'handler' => function() {
                return ['users' => [['id' => 1, 'name' => 'Alice'], ['id' => 2, 'name' => 'Bob']]];
            }
        ],
        [
            'method' => 'POST',
            'path' => '/users',
            'handler' => function($request) {
                $data = json_decode($request->getBody()->getContents(), true);
                // Simula criação de usuário
                return ['message' => "User '{$data['name']}' created successfully!"];
            }
        ]
    ]
];