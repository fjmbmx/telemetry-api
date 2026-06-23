<?php

namespace App\Infrastructure\Http\Controllers;

use App\Application\DeferredTasks;
use App\Infrastructure\Persistence\SensorDao;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SensorController
{
    private const ADDITIVE_SENSORS = [
        'S-HUM', 'S-TEMP', 'S-DS18B20', 'S-RPM-FAN',
        'S-POLLUTION', 'S-DS18B20_IZQ', 'S-DS18B20_DER', 'S-VOLTAJE',
    ];
    private const MULTIPLICATIVE_SENSORS = ['S-CLP'];

    // Metadata per sensor: icon, label, unit, decimal precision, optional ID override
    private const SENSOR_META = [
        'S-TEMP'        => ['id' => 'S-TEMP',        'icon' => '🌡️', 'label' => 'Sensor de Temperatura',         'unit' => '°C',  'dec' => 2],
        'S-HUM'         => ['id' => 'S-HUM',         'icon' => '💧', 'label' => 'Sensor de Humedad',              'unit' => '%',   'dec' => 2],
        'S-CLP'         => ['id' => 'S-LPA',         'icon' => '🧪', 'label' => 'Sensor de LPA',                  'unit' => '%',   'dec' => 2],
        'S-DS18B20'     => ['id' => 'S-DS18B20',     'icon' => '🩺', 'label' => 'Sensor de Sonda S-DS18B20',      'unit' => '°C',  'dec' => 1],
        'S-POLLUTION'   => ['id' => 'S-POLLUTION',   'icon' => '💭', 'label' => 'Sensor de Polvo',                'unit' => '%',   'dec' => 2],
        'S-RPM-FAN'     => ['id' => 'S-RPM-FAN',     'icon' => '☢',  'label' => 'Sensor de RPM',                  'unit' => '',    'dec' => 2],
        'S-DS18B20_IZQ' => ['id' => 'S-DS18B20_IZQ', 'icon' => '↖️', 'label' => 'Sensor de DS18B20_IZQ',          'unit' => '°C',  'dec' => 1],
        'S-DS18B20_DER' => ['id' => 'S-DS18B20_DER', 'icon' => '↗',  'label' => 'Sensor de DS18B20_DER',          'unit' => '°C',  'dec' => 1],
        'S-VOLTAGE'     => ['id' => 'S-VOLTAGE',     'icon' => '⚡︎', 'label' => 'Sensor de Voltaje',              'unit' => 'V',   'dec' => 1],
        'S-CURRENT'     => ['id' => 'S-CURRENT',     'icon' => '⚡︎', 'label' => 'Sensor de Corriente',            'unit' => 'A',   'dec' => 1],
        'S-POWER'       => ['id' => 'S-POWER',       'icon' => '⚡︎', 'label' => 'Sensor de Potencia',             'unit' => 'W',   'dec' => 1],
        'S-ENERGY'      => ['id' => 'S-ENERGY',      'icon' => '⚡︎', 'label' => 'Sensor de Energia',              'unit' => 'kWH', 'dec' => 1],
        'S-FREQUENCY'   => ['id' => 'S-FREQUENCY',   'icon' => '⚡︎', 'label' => 'Sensor de Frecuencia',           'unit' => 'Hz',  'dec' => 1],
        'S-PF'          => ['id' => 'S-PF',          'icon' => '⚡︎', 'label' => 'Sensor de Factor de Potencia',   'unit' => '',    'dec' => 1],
        'S-TDF'         => ['id' => 'S-TDF',         'icon' => '⚡︎', 'label' => 'Sensor Punto de Rocio',          'unit' => '°C',  'dec' => 1],
        'S-PRESION'     => ['id' => 'S-PRESION',     'icon' => '⚡︎', 'label' => 'Sensor de Presión',              'unit' => 'Bara','dec' => 1],
    ];

    public function __construct(private SensorDao $sensorDao) {}

    /** POST /devices/sensores — IoT ingest from Arduino/ESP32 */
    public function saveData(Request $request, Response $response): Response
    {
        // getParsedBody() only works when Content-Type: application/json is set.
        // Many Arduino/ESP32 libraries omit that header or send application/x-www-form-urlencoded,
        // in which case PSR-7 parses the JSON string as a single malformed form key.
        // Always fall back to raw body JSON parse when the expected keys are missing.
        $body = (array)$request->getParsedBody();
        if (!isset($body['codeClient'], $body['codeDevice'])) {
            $raw    = (string)$request->getBody();
            $parsed = json_decode($raw, true);
            if (is_array($parsed)) {
                $body = $parsed;
            }
        }

        $clientCode = trim($body['codeClient'] ?? '');
        $deviceCode = trim($body['codeDevice'] ?? '');
        $sensorData = $body['sensorData'] ?? [];

        if ($clientCode === '' || $deviceCode === '' || !is_array($sensorData)) {
            return $this->json($response, ['error' => 'Payload inválido'], 400);
        }

        $deviceSensors = $this->sensorDao->findByClientAndDeviceCode($clientCode, $deviceCode);
        if (empty($deviceSensors)) {
            return $this->json($response, ['error' => 'Dispositivo no encontrado'], 404);
        }

        $adjusted = $this->applyFactors($deviceSensors, $sensorData);
        $now      = date('Y-m-d H:i:s');

        // O(n) match using lookup map — avoids O(n²) nested foreach
        $byCode = array_column($adjusted, null, 'codSensor');
        $rows   = [];
        foreach ($deviceSensors as $ds) {
            $req = $byCode[$ds['code_sensor']] ?? null;
            if ($req) {
                $rows[] = [
                    'id_device'  => (int)$ds['id_device'],
                    'id_sensor'  => (int)$ds['id_sensor'],
                    'value'      => (float)$req['value'],
                    'maxValue'   => (float)($ds['max'] ?? 0),
                    'minValue'   => (float)($ds['min'] ?? 0),
                    'created_at' => $now,
                ];
            }
        }

        // Single INSERT with all sensor rows — replaces N individual INSERTs
        $this->sensorDao->saveBatch($rows);

        // Build client info object (same structure the legacy used for email/RabbitMQ)
        $first      = $deviceSensors[0] ?? [];
        $clientInfo = (object)[
            'client_name'  => $first['clientName']  ?? '',
            'client_code'  => $first['clientCode']  ?? '',
            'device_model' => $first['modelDevice'] ?? '',
            'device_name'  => $first['deviceName']  ?? '',
            'device_code'  => $first['deviceCode']  ?? '',
        ];

        // Notificaciones (RabbitMQ) y publicación MQTT corren diferidas en segundo
        // plano (ver fastcgi_finish_request en index.php), para responder al device
        // de inmediato. RabbitMQ se mantiene igual que desde el inicio: solo se
        // encola cuando se excede un umbral (consumidor de WhatsApp ya implementado).
        [$notifications, $alertExceeded] = $this->buildNotifications($deviceSensors, $adjusted);

        DeferredTasks::add(function () use (
            $alertExceeded, $notifications, $clientInfo,
            $clientCode, $deviceCode, $deviceSensors, $adjusted
        ) {
            if ($alertExceeded) {
                $this->sendToQueue($notifications, $clientInfo);
            }
            $this->sendToMqtt($clientCode, $deviceCode, $clientInfo, $deviceSensors, $adjusted);
        });

        return $this->json($response, ['result' => true]);
    }

    private function applyFactors(array $deviceSensors, array $sensorData): array
    {
        $out = [];
        foreach ($deviceSensors as $ds) {
            foreach ($sensorData as $req) {
                if ($ds['code_sensor'] !== $req['codSensor']) continue;
                $v      = (float)$req['value'];
                $factor = (float)($ds['factor'] ?? 0);
                if ($factor != 0) {
                    if (in_array($ds['code_sensor'], self::MULTIPLICATIVE_SENSORS, true)) {
                        $v *= $factor;
                    } elseif (in_array($ds['code_sensor'], self::ADDITIVE_SENSORS, true)) {
                        $v += $factor;
                    }
                }
                $out[] = array_merge($req, [
                    'value'    => $v,
                    'maxValue' => (float)($ds['max'] ?? 0),
                    'minValue' => (float)($ds['min'] ?? 0),
                ]);
            }
        }
        return $out;
    }

    /**
     * Builds per-sensor notification objects and checks thresholds.
     * Replaces the legacy validateData() + switch($code) block.
     * Returns [notifications[], alertExceeded].
     */
    private function buildNotifications(array $deviceSensors, array $adjusted): array
    {
        $byCode           = array_column($adjusted, null, 'codSensor');
        $notifications    = [];
        $alertExceeded    = false;

        foreach ($deviceSensors as $ds) {
            $code = $ds['code_sensor'];
            $req  = $byCode[$code] ?? null;
            if ($req === null) continue;

            $meta = self::SENSOR_META[$code] ?? null;
            if ($meta === null) continue;

            $val     = (float)$req['value'];
            $min     = $ds['min'] !== null ? (float)$ds['min'] : null;
            $max     = $ds['max'] !== null ? (float)$ds['max'] : null;
            $unit    = $meta['unit'];
            $dec     = $meta['dec'];
            $rounded = round($val, $dec);

            $n = new \stdClass();
            $n->ID                  = $meta['id'];
            $n->icon                = $meta['icon'];
            $n->labelNotification   = $meta['label'];
            $n->maxValue            = round($max ?? 0, 2) . ($unit ? " {$unit}" : '');
            $n->minValue            = round($min ?? 0, 2) . ($unit ? " {$unit}" : '');
            $n->whatsappValue       = $rounded . ($unit ? " {$unit}" : '');

            // Special case: DS18B20 disconnected sentinel value
            if ($code === 'S-DS18B20' && $val == -127) {
                $n->whatsappValue = '0 °C';
                $n->currentValue  = "<strong class=alert-noconnected style='font-size:20px'>{$rounded} °C</strong>";
                $notifications[]  = $n;
                continue;
            }

            $exceeded = ($min !== null && $val < $min) || ($max !== null && $val > $max);

            if ($exceeded) {
                $n->currentValue = "<strong class=alert-danger style='font-size:20px'>{$rounded}" . ($unit ? " {$unit}" : '') . "</strong>";
                $alertExceeded   = true;
            } else {
                $n->currentValue = "<strong class=alert-success style='font-size:20px'>{$rounded}" . ($unit ? " {$unit}" : '') . "</strong>";
            }

            $notifications[] = $n;
        }

        return [$notifications, $alertExceeded];
    }

    /** Sends alert notifications to RabbitMQ — only called when a threshold is exceeded. */
    private function sendToQueue(array $notifications, object $clientInfo): void
    {
        $senderFile = __DIR__ . '/../../../../php/rabbitmq/WorkerSender.php';
        if (!file_exists($senderFile)) return;

        try {
            require_once $senderFile;
            if (!class_exists('WorkerSender')) return;

            $data = (object)[
                'clientData'    => $clientInfo,
                'alert_subject' => '! Alerta ! Sensores - ' . $clientInfo->device_name,
                'data_sensors'  => $notifications,
            ];

            $sender = new \WorkerSender();
            $sender->execute($data, 'router_telemetry', 'notifications_qa');
        } catch (\Throwable) {
            // RabbitMQ failure must not block the ingest response
        }
    }

    /**
     * Publica el reporte completo del dispositivo a MQTT, en UN solo mensaje:
     * topic {MQTT_TOPIC_PREFIX}/{clientCode}/{deviceCode}, payload con
     * deviceName, deviceModel, timestamp, alert y sensors[] (cada uno con su flag).
     */
    private function sendToMqtt(
        string $clientCode, string $deviceCode,
        object $clientInfo, array $deviceSensors, array $adjusted
    ): void {
        $mqttConfig = __DIR__ . '/../../../../php/mqtt/config.php';
        if (!file_exists($mqttConfig)) return;

        try {
            require_once $mqttConfig;
            if (!defined('MQTT_ENABLED') || !MQTT_ENABLED) return;

            $mqttSender = __DIR__ . '/../../../../php/mqtt/MqttSender.php';
            if (!file_exists($mqttSender)) return;
            require_once $mqttSender;

            // Flag de alerta por sensor segun umbrales (solo informativo en el payload)
            $byCode        = array_column($deviceSensors, null, 'code_sensor');
            $sensors       = [];
            $alertExceeded = false;
            foreach ($adjusted as $sensor) {
                $ds    = $byCode[$sensor['codSensor']] ?? null;
                $alert = false;
                if ($ds) {
                    $min = $ds['min'] !== null ? (float)$ds['min'] : null;
                    $max = $ds['max'] !== null ? (float)$ds['max'] : null;
                    $v   = (float)$sensor['value'];
                    if (($min !== null && $v < $min) || ($max !== null && $v > $max)) {
                        $alert         = true;
                        $alertExceeded = true;
                    }
                }
                $sensors[] = [
                    'codSensor' => $sensor['codSensor'],
                    'value'     => $sensor['value'],
                    'alert'     => $alert,
                ];
            }

            $payload = json_encode([
                'codeClient'  => $clientCode,
                'codeDevice'  => $deviceCode,
                'deviceName'  => $clientInfo->device_name,
                'deviceModel' => $clientInfo->device_model,
                'timestamp'   => date('c'),
                'alert'       => $alertExceeded,
                'sensors'     => $sensors,
            ]);

            $topic = MQTT_TOPIC_PREFIX . "/{$clientCode}/{$deviceCode}";
            $mqtt  = new \MqttSender();
            $mqtt->publish($topic, $payload, MQTT_RETAIN);
        } catch (\Throwable) {
            // MQTT failure must not block the ingest response
        }
    }

    private function json(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
