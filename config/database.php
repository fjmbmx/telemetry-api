<?php

return [
    'host'    => $_ENV['DB_HOST']    ?? 'localhost',
    'port'    => $_ENV['DB_PORT']    ?? '3306',
    'dbname'  => $_ENV['DB_NAME']    ?? '',
    'user'    => $_ENV['DB_USER']    ?? '',
    'pass'    => $_ENV['DB_PASS']    ?? '',
    'charset' => $_ENV['DB_CHARSET'] ?? 'utf8mb4',
];
