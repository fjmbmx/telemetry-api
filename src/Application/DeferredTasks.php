<?php

namespace App\Application;

/**
 * Cola simple de tareas a ejecutar DESPUES de enviar la respuesta al cliente.
 * Se usa para sacar MQTT/RabbitMQ del camino critico del request: el Arduino
 * recibe su respuesta de inmediato y las publicaciones corren en segundo plano.
 *
 * Estado estatico = válido porque cada request PHP es un proceso aislado.
 */
class DeferredTasks
{
    /** @var callable[] */
    private static array $tasks = [];

    public static function add(callable $task): void
    {
        self::$tasks[] = $task;
    }

    public static function runAll(): void
    {
        while ($task = array_shift(self::$tasks)) {
            try {
                $task();
            } catch (\Throwable) {
                // Una falla en segundo plano no debe afectar nada (el cliente ya respondió)
            }
        }
    }
}
