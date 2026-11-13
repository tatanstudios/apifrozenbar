<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rechaza las peticiones de usuarios desactivados aunque su token siga vigente.
 */
class UsuarioActivo
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && !$user->estado) {
            $user->currentAccessToken()?->delete();

            return response()->json([
                'success' => false,
                'status'  => 'USER_INACTIVE',
                'message' => 'Tu usuario está desactivado. Contacta al administrador.',
            ], 401);
        }

        return $next($request);
    }
}
