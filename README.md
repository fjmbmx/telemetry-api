# telemetry-api

API de **ingesta** de telemetría. Es el único servicio que usan los Arduino/ESP32 para
**guardar la información** de los sensores. Está separada del dashboard para que los
despliegues de consulta **no interrumpan** la recepción de datos.

## Endpoints

- **Nuevo (este proyecto):** `POST https://tele-metry.net/sensores-api/php/index.php/devices/sensores`
- **Firmware actual (en producción):** `POST https://tele-metry.net/sensores/php/index.php/devices/sensores`

Se despliega a `public_html/sensores-api/`, así que **convive en paralelo** con la
ingesta viva en `/sensores/` sin tocarla. El firmware seguirá pegando a `/sensores/`
hasta que (a) actualices su URL a `/sensores-api/`, o (b) hagas el swap de carpetas.

```
POST https://tele-metry.net/sensores-api/php/index.php/devices/sensores
Content-Type: application/json

{
  "codeClient": "C-001",
  "codeDevice": "D-001",
  "sensorData": [
    { "codSensor": "S-TEMP", "value": 24.5 },
    { "codSensor": "S-HUM",  "value": 60.0 }
  ]
}
```

Flujo (`SensorController::saveData`):
1. Busca el device/sensores y umbrales (`SensorDao`, cache 60s).
2. Aplica factores de corrección e inserta en `data_report` (un solo INSERT batch).
3. Si algún umbral se excede → envía alerta a **RabbitMQ** (`php/rabbitmq/WorkerSender`).
4. Publica el reporte a **MQTT** (`php/mqtt/MqttSender`).

Las publicaciones a RabbitMQ/MQTT están protegidas con `file_exists()`: si falta su
config, la ingesta **sigue guardando en DB** sin romperse.

## Estructura

```
php/index.php                  Router Slim (solo /devices/sensores y /health)
src/Application/Middleware/     CORS + JSON
src/Infrastructure/Http/...     SensorController
src/Infrastructure/Persistence/ SensorDao, PdoDatabase
config/database.php             Lee credenciales del .env
php/rabbitmq/                   WorkerSender + vendor propio (php-amqplib)
php/mqtt/                       MqttSender (socket MQTT puro)
vendor/                         Dependencias (commiteadas: Slim, php-di, dotenv)
```

## Configuración (en el servidor, NO en git)

Estos archivos llevan credenciales y están en `.gitignore`. Hay que crearlos en el
servidor (se **preservan** entre despliegues):

- `.env` ← copiar de `.env.example` (DB; usar un usuario dedicado `..._ingest`)
- `php/rabbitmq/config.php` ← copiar de `config.example.php` (CloudAMQP)
- `php/mqtt/config.php` ← copiar de `config.example.php` (MQTT)

## Despliegue

Manual desde **Actions → Run workflow** (no automático). Sube el zip por **HTTPS** a
`receive.php` (Hostinger bloquea FTP desde GitHub) y lo extrae en
`public_html/sensores-api/` con backup y preservando los archivos de config.

Requiere el secret **`DEPLOY_TOKEN`** (mismo valor que el `receive.php` del servidor).

> Primer despliegue: subir antes `.env` + los `config.php` a `public_html/sensores-api/`,
> o se quedará sin credenciales.
