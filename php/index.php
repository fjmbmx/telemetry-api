<?php
declare(strict_types=1);

use App\Application\Middleware\CorsMiddleware;
use App\Application\Middleware\JsonMiddleware;
use App\Infrastructure\Http\Controllers\SensorController;
use DI\ContainerBuilder;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

// Mexico does not observe DST since 2023 — fixed at UTC-6
date_default_timezone_set('America/Mexico_City');

// Load .env
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

// Build DI container (compilado en produccion -> bootstrap mas rapido)
$builder = new ContainerBuilder();
$builder->addDefinitions(require __DIR__ . '/../config/dependencies.php');
if (($_ENV['APP_ENV'] ?? 'production') === 'production') {
    // sys_get_temp_dir() es escribible en Hostinger (ahí cachea tambien el SensorDao)
    $cacheDir = sys_get_temp_dir() . '/telemetry-api-di';
    if ((is_dir($cacheDir) || @mkdir($cacheDir, 0775, true)) && is_writable($cacheDir)) {
        $builder->enableCompilation($cacheDir);
    }
}
$container = $builder->build();

// Create Slim app
AppFactory::setContainer($container);
$app = AppFactory::create();

// Base path: everything up to and including "index.php"
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '/php/index.php';
$app->setBasePath($scriptName);

// Global middleware
$app->add(CorsMiddleware::class);
$app->add(JsonMiddleware::class);
$app->addRoutingMiddleware();
$app->addErrorMiddleware(false, true, true);

// ─── Rutas ──────────────────────────────────────────────────────────────────

// Ingesta IoT — Arduino/ESP32 (autentican por clientCode/deviceCode, sin JWT)
$app->post('/devices/sensores', [SensorController::class, 'saveData']);

// Health-check
$app->get('/health', function ($request, $response) {
    $response->getBody()->write(json_encode([
        'status'  => 'ok',
        'service' => 'telemetry-api',
        'time'    => date('c'),
    ]));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->run();

// Enviar la respuesta al cliente YA y cerrar la conexion; lo pesado (MQTT/RabbitMQ)
// corre en segundo plano sin que el Arduino lo espere.
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} elseif (function_exists('litespeed_finish_request')) {
    litespeed_finish_request();
}

\App\Application\DeferredTasks::runAll();
