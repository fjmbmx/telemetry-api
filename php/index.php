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

// Build DI container
$builder = new ContainerBuilder();
$builder->addDefinitions(require __DIR__ . '/../config/dependencies.php');
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
