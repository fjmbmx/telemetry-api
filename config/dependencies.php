<?php

use App\Infrastructure\Persistence\PdoDatabase;
use App\Infrastructure\Persistence\SensorDao;
use App\Infrastructure\Http\Controllers\SensorController;
use Psr\Container\ContainerInterface;

return [
    // Conexion a la base de datos (lee config/database.php -> .env)
    PdoDatabase::class => function () {
        $cfg = require __DIR__ . '/database.php';
        return new PdoDatabase($cfg);
    },

    // DAO y controlador de la ingesta
    SensorDao::class        => fn(ContainerInterface $c) => new SensorDao($c->get(PdoDatabase::class)),
    SensorController::class => fn(ContainerInterface $c) => new SensorController($c->get(SensorDao::class)),
];
