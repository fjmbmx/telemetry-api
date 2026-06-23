<?php

namespace App\Infrastructure\Persistence;

class SensorDao
{
    public function __construct(private PdoDatabase $db) {}

    /** Returns device/sensor config rows with thresholds. Result is file-cached for 60s. */
    public function findByClientAndDeviceCode(string $clientCode, string $deviceCode): array
    {
        $cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'device_' . md5($clientCode . '_' . $deviceCode) . '.json';

        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 60) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if (is_array($cached)) return $cached;
        }

        $result = $this->db->query(
            'SELECT c.id_client, c.name AS clientName, c.code AS clientCode,
                    d.id_device, d.name AS deviceName, d.code AS deviceCode, d.model AS modelDevice,
                    s.id_sensor, s.code AS code_sensor,
                    ds.correction_factor AS factor, ds.max, ds.min
             FROM client c
             INNER JOIN client_device cd ON cd.id_client = c.id_client
             INNER JOIN device d          ON d.id_device  = cd.id_device
             INNER JOIN device_sensor ds  ON ds.id_device = d.id_device
             INNER JOIN sensor s          ON s.id_sensor  = ds.id_sensor
             WHERE c.code = ? AND d.code = ?',
            [$clientCode, $deviceCode]
        );

        @file_put_contents($cacheFile, json_encode($result));
        return $result;
    }

    /** Inserts all sensor readings for one IoT payload in a single round trip. */
    public function saveBatch(array $rows): void
    {
        if (empty($rows)) return;

        $placeholders = implode(', ', array_fill(0, count($rows), '(?, ?, ?, ?, ?, 0, ?)'));
        $sql = "INSERT INTO data_report (id_device, id_sensor, valor, max_value, min_value, alert, created_at)
                VALUES {$placeholders}";

        $params = [];
        foreach ($rows as $row) {
            $params[] = $row['id_device'];
            $params[] = $row['id_sensor'];
            $params[] = $row['value'];
            $params[] = $row['maxValue'];
            $params[] = $row['minValue'];
            $params[] = $row['created_at'];
        }

        $this->db->execute($sql, $params);
    }
}
