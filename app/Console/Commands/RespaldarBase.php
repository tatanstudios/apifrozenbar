<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Respaldo completo de la base de datos con mysqldump (estructura, datos, rutinas
 * y disparadores) en storage/app/respaldos, comprimido con gzip. Borra los
 * respaldos automáticos más viejos que los días indicados.
 *
 *   php artisan pos:respaldo                    # respaldo_2026-11-06_2300.sql.gz
 *   php artisan pos:respaldo --nombre=antes_de_limpiar
 *   php artisan pos:respaldo --dias=30
 *
 * MYSQLDUMP_PATH en .env indica dónde está mysqldump si no está en el PATH
 * (en Laragon: C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysqldump.exe).
 */
class RespaldarBase extends Command
{
    protected $signature = 'pos:respaldo {--nombre= : Nombre del respaldo (no se borra con la limpieza automática)} {--dias=30 : Días que se guardan los respaldos automáticos}';

    protected $description = 'Respalda la base de datos completa en storage/app/respaldos';

    public function handle(): int
    {
        $db = config('database.connections.' . config('database.default'));
        if (($db['driver'] ?? '') !== 'mysql' && ($db['driver'] ?? '') !== 'mariadb') {
            $this->error('El respaldo solo funciona con MySQL o MariaDB');
            return self::FAILURE;
        }

        $carpeta = storage_path('app/respaldos');
        File::ensureDirectoryExists($carpeta);

        $manual = (string) $this->option('nombre');
        $base = $manual !== ''
            ? preg_replace('/[^A-Za-z0-9_\-]/', '_', $manual) . '_' . now()->format('Y-m-d_Hi')
            : 'respaldo_' . now()->format('Y-m-d_Hi');
        $archivo = "{$carpeta}/{$base}.sql.gz";

        $proceso = new Process([
            env('MYSQLDUMP_PATH', 'mysqldump'),
            '--host=' . $db['host'], '--port=' . $db['port'], '--user=' . $db['username'],
            '--single-transaction', '--quick', '--routines', '--triggers', '--no-tablespaces',
            '--default-character-set=utf8mb4',
            $db['database'],
        ], null, ['MYSQL_PWD' => (string) $db['password']], null, 600);

        $gz = gzopen($archivo, 'wb6');
        $errores = '';
        $proceso->run(function ($tipo, $salida) use ($gz, &$errores) {
            if ($tipo === Process::OUT) {
                gzwrite($gz, $salida);
            } else {
                $errores .= $salida;
            }
        });
        gzclose($gz);

        if (!$proceso->isSuccessful()) {
            File::delete($archivo);
            $this->error('No se pudo respaldar: ' . trim($errores ?: $proceso->getErrorOutput()));
            return self::FAILURE;
        }

        $tamano = round(filesize($archivo) / 1024, 1);
        $this->info("Respaldo creado: storage/app/respaldos/{$base}.sql.gz ({$tamano} KB)");

        // Limpieza: solo los automáticos (respaldo_*) más viejos que --dias
        $limite = now()->subDays(max(1, (int) $this->option('dias')))->getTimestamp();
        foreach (File::glob("{$carpeta}/respaldo_*.sql.gz") as $viejo) {
            if (filemtime($viejo) < $limite) {
                File::delete($viejo);
                $this->line('Borrado por antigüedad: ' . basename($viejo));
            }
        }

        return self::SUCCESS;
    }
}
