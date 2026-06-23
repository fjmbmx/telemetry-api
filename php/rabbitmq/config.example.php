<?php
require_once __DIR__ . '/vendor/autoload.php';

// Credenciales de CloudAMQP — copiar a config.php y completar
define('HOST', 'fish-01.rmq.cloudamqp.com');
define('PORT', 5672);
define('USER', 'CHANGE_ME');
define('PASS', 'CHANGE_ME');
define('VHOST', 'CHANGE_ME');

define('AMQP_DEBUG', false);
