<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\Administrador;
use App\Services\AuditoriaService;
use App\Services\AutorizacionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\PersonalAccessToken;

class AuthApiController extends Controller
{
    public function me(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'nombre' => $user->nombre,
            'email' => $user->email,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
            'tiene_pin' => !empty($user->pin),
            'pin_actualizado_at' => $user->pin_actualizado_at?->format('Y-m-d H:i'),
        ]);
    }

    // PUT /me/pin {password_actual, pin, pin_confirmation}: cada quien cambia su PIN de autorización
    public function cambiarPin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'password_actual' => 'required|string',
            'pin'             => 'required|digits_between:4,6|confirmed',
        ], [
            'password_actual.required' => 'Escribe tu contraseña actual',
            'pin.required'             => 'Escribe el PIN nuevo',
            'pin.digits_between'       => 'El PIN debe tener de 4 a 6 números',
            'pin.confirmed'            => 'La confirmación no coincide con el PIN',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        if (!Hash::check($request->input('password_actual'), $user->password)) {
            return response()->json(['success' => false, 'message' => 'La contraseña actual no es correcta'], 422);
        }
        if (AutorizacionService::pinEnUso($request->input('pin'), $user->id)) {
            return response()->json(['success' => false, 'message' => 'Ese PIN no está disponible, elige otro'], 422);
        }

        $antes = $user->pin_actualizado_at?->toDateTimeString();
        $user->forceFill(['pin' => $request->input('pin'), 'pin_actualizado_at' => now()])->save();

        AuditoriaService::registrar('usuarios', 'cambiar_pin', $user, ['pin_actualizado_at' => $antes], ['pin_actualizado_at' => $user->pin_actualizado_at->toDateTimeString()]);

        return response()->json([
            'success' => 1,
            'message' => 'PIN guardado',
            'pin_actualizado_at' => $user->pin_actualizado_at->format('Y-m-d H:i'),
        ]);
    }

    // POST /me/desbloquear {codigo}: quita el bloqueo de pantalla con tu PIN (o tu contraseña si no tienes PIN)
    public function desbloquear(Request $request)
    {
        $codigo = trim((string) $request->input('codigo'));
        if ($codigo === '') {
            return response()->json(['success' => false, 'message' => 'Escribe tu PIN'], 422);
        }

        $user = $request->user();
        $clave = 'desbloquear:' . $user->id;
        if (RateLimiter::tooManyAttempts($clave, AutorizacionService::INTENTOS_ENTRAR)) {
            $segundos = RateLimiter::availableIn($clave);
            return response()->json(['success' => false, 'message' => "Demasiados intentos. Espera {$segundos} segundos.", 'segundos' => $segundos], 429);
        }

        $valido = ($user->pin && Hash::check($codigo, $user->pin)) || Hash::check($codigo, $user->password);
        if (!$valido) {
            RateLimiter::hit($clave, 60);
            return response()->json(['success' => false, 'message' => $user->pin ? 'PIN incorrecto' : 'Contraseña incorrecta'], 422);
        }

        RateLimiter::clear($clave);
        return response()->json(['success' => 1]);
    }

    /**
     * POST /me/cambiar-usuario {pin}: en la tablet bloqueada, quien escribe su PIN entra con su
     * propia sesión sin correo ni contraseña (una sola tablet para varios meseros). El PIN no se
     * repite entre usuarios, así que identifica a la persona. La sesión anterior se cierra.
     */
    public function cambiarUsuario(Request $request)
    {
        $pin = trim((string) $request->input('pin'));
        if ($pin === '') {
            return response()->json(['success' => false, 'message' => 'Escribe tu PIN'], 422);
        }

        $actual = $request->user();
        $token = $actual->currentAccessToken();
        // Por dispositivo: el límite no se reinicia al cambiar de persona
        $clave = 'cambiar_usuario:' . ($token instanceof PersonalAccessToken ? $token->id : $actual->id);
        if (RateLimiter::tooManyAttempts($clave, AutorizacionService::INTENTOS_ENTRAR)) {
            $segundos = RateLimiter::availableIn($clave);
            return response()->json(['success' => false, 'message' => "Demasiados intentos. Espera {$segundos} segundos.", 'segundos' => $segundos], 429);
        }

        $nuevo = AutorizacionService::usuarioPorPin($pin);
        if (!$nuevo) {
            RateLimiter::hit($clave, 60);
            return response()->json(['success' => false, 'message' => 'PIN incorrecto'], 422);
        }
        RateLimiter::clear($clave);

        // La misma persona: solo desbloquea
        if ($nuevo->id === $actual->id) {
            return response()->json(['success' => 1, 'cambio' => false, 'usuario_id' => $actual->id]);
        }

        $dispositivo = $token instanceof PersonalAccessToken ? $token->name : 'tablet-meseros';
        // Como al entrar: la sesión no vence hasta cerrar sesión
        $plano = $nuevo->createToken($dispositivo)->plainTextToken;

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }
        $nuevo->forceFill(['ultimo_acceso' => now()])->save();

        AuditoriaService::registrar('auth', 'cambio_usuario', $nuevo, null,
            ['dispositivo' => $dispositivo, 'antes' => $actual->nombre], null, $nuevo->id);

        return response()->json(['success' => 1, 'cambio' => true, 'usuario_id' => $nuevo->id, 'token' => $plano]);
    }

    // DELETE /admin/usuarios/{usuario}/pin: el administrador borra el PIN de quien lo olvidó;
    // esa persona desbloquea con su contraseña y crea uno nuevo en "Mi cuenta"
    public function resetearPin(Administrador $usuario)
    {
        if (!$usuario->pin) {
            return response()->json(['success' => false, 'message' => "{$usuario->nombre} no tiene PIN"], 422);
        }

        $antes = $usuario->pin_actualizado_at?->toDateTimeString();
        $usuario->forceFill(['pin' => null, 'pin_actualizado_at' => null])->save();

        AuditoriaService::registrar('usuarios', 'resetear_pin', $usuario, ['pin_actualizado_at' => $antes], ['pin_actualizado_at' => null]);

        return response()->json(['success' => 1, 'message' => "PIN de {$usuario->nombre} borrado. Ahora entra con su contraseña y crea uno nuevo."]);
    }
}
