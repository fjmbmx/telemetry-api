<?php
// Config MQTT tomada del .env (cargado por Dotenv en php/index.php).
// Sin secretos hardcodeados -> este archivo se puede versionar.
define('MQTT_HOST',          $_ENV['MQTT_HOST'] ?? 'localhost');
define('MQTT_PORT',          (int)($_ENV['MQTT_PORT'] ?? 1883));
define('MQTT_USER',          $_ENV['MQTT_USER'] ?? '');
define('MQTT_PASSWORD',      $_ENV['MQTT_PASSWORD'] ?? '');
define('MQTT_CLIENT_PREFIX', $_ENV['MQTT_CLIENT_PREFIX'] ?? 'telemetry-api-');
define('MQTT_TOPIC_PREFIX',  $_ENV['MQTT_TOPIC_PREFIX'] ?? 'telemetry');
define('MQTT_RETAIN',        filter_var($_ENV['MQTT_RETAIN'] ?? false, FILTER_VALIDATE_BOOLEAN));
define('MQTT_ENABLED',       filter_var($_ENV['MQTT_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN));
define('MQTT_TIMEOUT',       (int)($_ENV['MQTT_TIMEOUT'] ?? 3));
