<?php

namespace App\Providers;

use App\Services\AutorizacionService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // El rol "admin" tiene acceso total, incluso a permisos agregados después
        Gate::before(function ($user) {
            return method_exists($user, 'hasRole') && $user->hasRole('admin') ? true : null;
        });

        // Inicio de sesión (correo o PIN): solo un freno contra inundaciones por IP, que el uso
        // normal nunca alcanza (varias tablets del local salen por la misma IP). Los intentos
        // FALLIDOS se limitan en el controlador: 25 seguidos y hay que esperar un minuto
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
        RateLimiter::for('login-pin', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));

        // La llave de una tablet registrada solo sirve para entrar con PIN, no como sesión
        Sanctum::authenticateAccessTokensUsing(
            fn (PersonalAccessToken $token, bool $valido) => $valido && $token->name !== AutorizacionService::LLAVE_TABLET
        );
    }
}
