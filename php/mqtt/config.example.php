<?php
// Config MQTT — copiar a config.php y completar credenciales
define('MQTT_HOST',          'mqtt.gnssys.com');
define('MQTT_PORT',          1883);
define('MQTT_USER',          'telemetry');
define('MQTT_PASSWORD',      'CHANGE_ME');
define('MQTT_CLIENT_PREFIX', 'telemetry-api-');
define('MQTT_TOPIC_PREFIX',  'telemetry');
define('MQTT_RETAIN',        false);
define('MQTT_ENABLED',       true);
define('MQTT_TIMEOUT',       3);
