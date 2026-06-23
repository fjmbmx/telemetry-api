<?php
// Credenciales tomadas del .env (cargado por Dotenv en php/index.php).
// Sin secretos hardcodeados -> este archivo se puede versionar.
require_once __DIR__ . '/vendor/autoload.php';

define('HOST',  $_ENV['RABBITMQ_HOST']  ?? 'localhost');
define('PORT',  (int)($_ENV['RABBITMQ_PORT'] ?? 5672));
define('USER',  $_ENV['RABBITMQ_USER']  ?? '');
define('PASS',  $_ENV['RABBITMQ_PASS']  ?? '');
define('VHOST', $_ENV['RABBITMQ_VHOST'] ?? '');

define('AMQP_DEBUG', false);
