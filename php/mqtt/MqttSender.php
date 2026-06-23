<?php
require_once __DIR__ . '/config.php';

class MqttSender
{
    private $socket;
    private $connected = false;

    public function __construct()
    {
        if (!MQTT_ENABLED) return;
        $this->connect();
    }

    private function encodeLength(int $len): string
    {
        $encoded = '';
        do {
            $byte = $len % 128;
            $len  = intdiv($len, 128);
            if ($len > 0) $byte |= 0x80;
            $encoded .= chr($byte);
        } while ($len > 0);
        return $encoded;
    }

    private function connect(): void
    {
        $socket = @fsockopen(MQTT_HOST, MQTT_PORT, $errno, $errstr, MQTT_TIMEOUT);
        if (!$socket) {
            error_log("MqttSender: no se pudo conectar a " . MQTT_HOST . ":" . MQTT_PORT . " — $errstr");
            return;
        }
        stream_set_timeout($socket, MQTT_TIMEOUT);
        $this->socket = $socket;

        $clientId = MQTT_CLIENT_PREFIX . substr(uniqid(), -6);

        $payload  = pack('n', 4) . 'MQTT';
        $payload .= "\x04";
        $payload .= "\xC2";
        $payload .= pack('n', 60);
        $payload .= pack('n', strlen($clientId)) . $clientId;
        $payload .= pack('n', strlen(MQTT_USER))     . MQTT_USER;
        $payload .= pack('n', strlen(MQTT_PASSWORD)) . MQTT_PASSWORD;

        $packet = "\x10" . $this->encodeLength(strlen($payload)) . $payload;
        fwrite($socket, $packet);

        $resp = fread($socket, 4);
        if (strlen($resp) >= 4 && ord($resp[0]) === 0x20 && ord($resp[3]) === 0x00) {
            $this->connected = true;
        } else {
            $code = strlen($resp) >= 4 ? ord($resp[3]) : 'n/a';
            error_log("MqttSender: CONNACK fallido, código: $code");
            fclose($socket);
            $this->socket = null;
        }
    }

    public function publish(string $topic, string $payload, bool $retain = false): bool
    {
        if (!$this->connected) return false;

        $firstByte = $retain ? "\x31" : "\x30";
        $body      = pack('n', strlen($topic)) . $topic . $payload;
        $packet    = $firstByte . $this->encodeLength(strlen($body)) . $body;

        return fwrite($this->socket, $packet) !== false;
    }

    public function publishSensorReport(
        string $codeClient,
        string $codeDevice,
        string $deviceName,
        string $deviceModel,
        array  $sensors,
        bool   $alertExceeded
    ): void {
        if (!$this->connected) return;

        $timestamp = date('c');
        $prefix    = MQTT_TOPIC_PREFIX;
        $retain    = MQTT_RETAIN;

        // Payload completo del dispositivo
        $deviceTopic   = "{$prefix}/{$codeClient}/{$codeDevice}";
        $devicePayload = json_encode([
            'codeClient'  => $codeClient,
            'codeDevice'  => $codeDevice,
            'deviceName'  => $deviceName,
            'deviceModel' => $deviceModel,
            'timestamp'   => $timestamp,
            'alert'       => $alertExceeded,
            'sensors'     => $sensors,
        ]);
        $this->publish($deviceTopic, $devicePayload, $retain);

        // Topic individual por sensor
        foreach ($sensors as $sensor) {
            $sensorTopic   = "{$deviceTopic}/{$sensor['codSensor']}";
            $sensorPayload = json_encode([
                'codSensor'  => $sensor['codSensor'],
                'value'      => $sensor['value'],
                'timestamp'  => $timestamp,
                'alert'      => $sensor['alert'],
            ]);
            $this->publish($sensorTopic, $sensorPayload, $retain);
        }
    }

    public function disconnect(): void
    {
        if ($this->socket) {
            fwrite($this->socket, "\xe0\x00");
            fclose($this->socket);
            $this->socket    = null;
            $this->connected = false;
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
