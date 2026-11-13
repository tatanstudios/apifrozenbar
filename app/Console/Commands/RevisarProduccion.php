<?php

namespace App\Console\Commands;

use App\Models\Administrador;
use App\Models\Impresora;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Process\Process;

/**
 * Lista de revisión antes de abrir el negocio con el sistema (y después de cada
 * actualización del servidor). No cambia nada: solo dice qué falta.
 *
 *   php artisan pos:revisar-produccion
 */
class RevisarProduccion extends Command
{
    protected $signature = 'pos:revisar-produccion';

    protected $description = 'Revisa la configuración del servidor antes de operar';

    private int $fallas = 0;
    private int $avisos = 0;

    public function handle(): int
    {
        $this->info('Configuración');
        $this->revisar(app()->environment('production'), 'APP_ENV=production', 'APP_ENV está en ' . app()->environment());
        $this->revisar(!config('app.debug'), 'APP_DEBUG=false', 'APP_DEBUG=true muestra errores internos a cualquiera');
        $this->revisar(!empty(config('app.key')), 'APP_KEY generada', 'Falta APP_KEY: php artisan key:generate');
        $this->revisar(str_starts_with((string) config('app.url'), 'https://'), 'APP_URL con https', 'APP_URL debe ser https://… (' . config('app.url') . ')');
        $this->aviso(config('logging.channels.single.level', 'debug') !== 'debug',
            'LOG_LEVEL distinto de debug', 'LOG_LEVEL=debug llena el disco: usa warning o error');
        $origenes = (array) config('cors.allowed_origins');
        $this->aviso($origenes !== ['*'], 'CORS limitado a ' . implode(', ', $origenes), 'CORS_ALLOWED_ORIGINS acepta cualquier sitio: pon la dirección de la web');
        $this->revisar(File::exists(public_path('storage')), 'Enlace public/storage', 'Falta el enlace de imágenes: php artisan storage:link');
        $this->aviso(app()->configurationIsCached(), 'Configuración en caché', 'Corre php artisan optimize después de cambiar .env o actualizar');

        $this->newLine();
        $this->info('Usuarios');
        $inseguras = Administrador::where('estado', true)->get()
            ->filter(fn ($u) => collect(['1234', '12345678', 'secreto123', 'password', 'admin'])->contains(fn ($c) => Hash::check($c, $u->password)));
        $this->revisar($inseguras->isEmpty(), 'Ninguna contraseña de prueba',
            'Cambia la contraseña de: ' . $inseguras->pluck('email')->implode(', '));
        $sinPin = Administrador::where('estado', true)->role('admin')->whereNull('pin')->pluck('email');
        $this->aviso($sinPin->isEmpty(), 'Los administradores tienen PIN', 'Sin PIN para autorizar en la tablet: ' . $sinPin->implode(', '));

        $this->newLine();
        $this->info('Impresoras');
        $activas = Impresora::where('activo', true)->get();
        $this->aviso($activas->isNotEmpty(), "{$activas->count()} impresora(s) activa(s)", 'No hay impresoras activas: todo se imprimirá desde el navegador');
        if ($this->enLaNube()) {
            $locales = $activas->where('conexion', '!=', 'dispositivo');
            $this->revisar($locales->isEmpty(), 'Todas se imprimen desde la tablet',
                'El servidor en la nube no llega a: ' . $locales->pluck('alias')->implode(', ') . ' → cámbialas a «Desde la tablet»');
        }

        $this->newLine();
        $this->info('Respaldos y tareas');
        $version = new Process([env('MYSQLDUMP_PATH', 'mysqldump'), '--version']);
        $version->run();
        $this->revisar($version->isSuccessful(), 'mysqldump disponible', 'No se encuentra mysqldump (MYSQLDUMP_PATH)');
        $ultimo = collect(File::glob(storage_path('app/respaldos/*.sql.gz')))->map(fn ($f) => filemtime($f))->max();
        $this->aviso($ultimo && $ultimo > now()->subHours(26)->getTimestamp(), 'Hay un respaldo de las últimas 26 horas',
            'No hay respaldo reciente: revisa el cron de schedule:run (o corre php artisan pos:respaldo)');

        $this->newLine();
        if ($this->fallas > 0) {
            $this->error("{$this->fallas} punto(s) por corregir y {$this->avisos} aviso(s).");
            return self::FAILURE;
        }
        $this->info($this->avisos > 0 ? "Listo para operar, con {$this->avisos} aviso(s)." : 'Listo para operar.');

        return self::SUCCESS;
    }

    // En producción el servidor está en la nube (decisión del 2026-11-06)
    private function enLaNube(): bool
    {
        return app()->environment('production');
    }

    private function revisar(bool $ok, string $bien, string $mal): void
    {
        $ok ? $this->line("  <fg=green>✔</> {$bien}") : $this->line("  <fg=red>✖</> {$mal}");
        $this->fallas += $ok ? 0 : 1;
    }

    private function aviso(bool $ok, string $bien, string $mal): void
    {
        $ok ? $this->line("  <fg=green>✔</> {$bien}") : $this->line("  <fg=yellow>!</> {$mal}");
        $this->avisos += $ok ? 0 : 1;
    }
}
