<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Crea avisos para los productos que ya están en alerta y que el usuario aún no tiene
Artisan::command('notificaciones:sincronizar-stock', function () {
    $creadas = \App\Services\NotificacionService::sincronizarStock();
    $this->info("{$creadas} notificación(es) creada(s)");
})->purpose('Genera notificaciones de stock bajo para las alertas actuales');

// Respaldo diario de la base (guarda 30 días). En el servidor se necesita el cron de
// Laravel: * * * * * cd /ruta/al/proyecto && php artisan schedule:run >> /dev/null 2>&1
Schedule::command('pos:respaldo --dias=30')->dailyAt('23:30')->withoutOverlapping();
