<?php

namespace App\Http\Controllers\Api\Login;

use App\Http\Controllers\Controller;
use App\Models\Administrador;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use App\Services\AuditoriaService;
use App\Services\AutorizacionService;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;

class LoginApiController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        // Solo cuentan los intentos fallidos: 25 seguidos con ese correo desde esa IP y hay que esperar
        $clave = 'login:' . mb_strtolower((string) $request->email) . '|' . $request->ip();
        if (RateLimiter::tooManyAttempts($clave, AutorizacionService::INTENTOS_ENTRAR)) {
            $segundos = RateLimiter::availableIn($clave);
            return response()->json([
                'success'  => false,
                'status'   => 'TOO_MANY_ATTEMPTS',
                'message'  => "Demasiados intentos fallidos. Espera {$segundos} segundos.",
                'segundos' => $segundos,
            ], 429);
        }

        $user = Administrador::where('email', $request->email)->first();

        // Mismo mensaje si el correo no existe o la contraseña es incorrecta,
        // para no revelar qué correos están registrados
        if (!$user || !Hash::check($request->password, $user->password)) {
            RateLimiter::hit($clave, 60);
            AuditoriaService::registrar('auth', 'login_fallido', $user, null, null, 'Correo: ' . $request->email, $user?->id);

            return response()->json([
                'success' => false,
                'status' => 'INVALID_CREDENTIALS',
                'message' => 'Correo o contraseña incorrectos.',
            ], 401);
        }

        RateLimiter::clear($clave);

        if (!$user->estado) {
            return response()->json([
                'success' => false,
                'status' => 'USER_INACTIVE',
                'message' => 'Tu usuario está desactivado. Contacta al administrador.',
            ], 403);
        }

        // Login exitoso - continúa con el token
        $deviceName = $request->device_name ?? 'default';
        // La sesión no vence (web ni tablet): dura hasta que se toca «Cerrar sesión»
        $token = $user->createToken($deviceName)->plainTextToken;

        $user->forceFill(['ultimo_acceso' => now()])->save();

        AuditoriaService::registrar('auth', 'login', $user, null, ['dispositivo' => $deviceName], null, $user->id);

        // Primera vez en una tablet: queda registrada y desde ahí se puede entrar con PIN
        $llave = $deviceName === 'tablet-meseros' && $request->boolean('registrar_dispositivo')
            ? $user->createToken(AutorizacionService::LLAVE_TABLET)->plainTextToken
            : null;

        return response()->json([
            'success' => true,
            'message' => 'Inicio de sesión exitoso.',
            'user' => $user,
            'token' => $token,
            'token_type' => 'Bearer',
        ] + ($llave ? ['dispositivo' => $llave] : []));
    }

    /**
     * POST /login-pin {dispositivo, pin}: en una tablet ya registrada (alguien entró antes con
     * correo y contraseña), cada usuario entra solo con su PIN. Sin la llave de la tablet no se
     * acepta: así no se pueden probar PINs desde cualquier lugar.
     */
    public function loginPin(Request $request)
    {
        $llave = PersonalAccessToken::findToken((string) $request->input('dispositivo'));
        if (!$llave || $llave->name !== AutorizacionService::LLAVE_TABLET || !$llave->tokenable?->estado) {
            return response()->json([
                'success' => false,
                'status'  => 'DISPOSITIVO_NO_REGISTRADO',
                'message' => 'Esta tablet ya no está registrada: entra con tu correo y contraseña.',
            ], 401);
        }

        $pin = trim((string) $request->input('pin'));
        if ($pin === '') {
            return response()->json(['success' => false, 'message' => 'Escribe tu PIN'], 422);
        }

        $clave = 'login_pin:' . $llave->id;
        if (RateLimiter::tooManyAttempts($clave, AutorizacionService::INTENTOS_ENTRAR)) {
            $segundos = RateLimiter::availableIn($clave);
            return response()->json(['success' => false, 'message' => "Demasiados intentos. Espera {$segundos} segundos.", 'segundos' => $segundos], 429);
        }

        $user = AutorizacionService::usuarioPorPin($pin);
        if (!$user) {
            RateLimiter::hit($clave, 60);
            return response()->json(['success' => false, 'message' => 'PIN incorrecto'], 422);
        }
        RateLimiter::clear($clave);

        $token = $user->createToken('tablet-meseros')->plainTextToken;
        $user->forceFill(['ultimo_acceso' => now()])->save();
        $llave->forceFill(['last_used_at' => now()])->save();

        AuditoriaService::registrar('auth', 'login', $user, null, ['dispositivo' => 'tablet-meseros', 'metodo' => 'pin'], null, $user->id);

        return response()->json([
            'success'    => true,
            'message'    => 'Inicio de sesión exitoso.',
            'user'       => $user,
            'token'      => $token,
            'token_type' => 'Bearer',
        ]);
    }

    // Métod protegido (requiere autenticación)
    public function logout(Request $request)
    {
        AuditoriaService::registrar('auth', 'logout', $request->user());

        // Eliminar el token actual
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => 1,
            'message' => 'Sesión cerrada correctamente.'
        ]);
    }






    public function enviarCorreoAdministrador(Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'email' => 'required|email|exists:administradores,email',
            ],
            [
                'email.required' => 'El correo es obligatorio.',
                'email.email' => 'Ingrese un correo válido.',
                'email.exists' => 'Este correo no está registrado.',
            ]
        );

        if ($validator->fails()) {

            $errors = $validator->errors();

            if ($errors->has('email')) {
                $message = $errors->first('email');

                $code = match (true) {
                    str_contains($message, 'obligatorio') => 'EMAIL_REQUIRED',
                    str_contains($message, 'válido')      => 'EMAIL_INVALID',
                    str_contains($message, 'registrado')  => 'EMAIL_NOT_FOUND',
                    default                               => 'EMAIL_ERROR',
                };
            }

            return response()->json([
                'success' => false,
                'code'    => $code ?? 'EMAIL_ERROR',
                'message' => $message ?? 'Error de validación.',
                'errors'  => $errors,
            ], 422);
        }

        $status = Password::broker('administradores')
            ->sendResetLink($request->only('email'));

        if ($status === Password::RESET_LINK_SENT) {
            return response()->json([
                'success' => true,
                'code'    => 'RESET_EMAIL_SENT',
                'message' => 'Se envió el enlace de recuperación al correo.',
            ]);
        }

        return response()->json([
            'success' => false,
            'code'    => 'RESET_EMAIL_FAILED',
            'message' => 'No se pudo enviar el correo. Intente más tarde.',
        ], 500);
    }





    public function validateResetToken(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
        ]);

        $token = $request->token;
        $email = $request->email;

        // Buscar el token en la tabla password_reset_tokens
        $resetRecord = DB::table('password_reset_tokens')
            ->where('email', $email)
            ->first();

        // Verificar si existe el registro
        if (!$resetRecord) {
            return response()->json([
                'message' => 'Token inválido o no encontrado'
            ], 400);
        }

        // Verificar si el token coincide
        if (!Hash::check($token, $resetRecord->token)) {
            return response()->json([
                'message' => 'Token inválido'
            ], 400);
        }

        // Verificar si el token ha expirado (60 minutos por defecto)
        $expiresAt = Carbon::parse($resetRecord->created_at)
            ->addMinutes(config('auth.passwords.administradores.expire', 60));

        if (Carbon::now()->isAfter($expiresAt)) {
            return response()->json([
                'message' => 'El token ha expirado'
            ], 400);
        }

        // Token válido
        return response()->json([
            'message' => 'Token válido'
        ]);
    }





    /**
     * Restablecer contraseña con token
     */
    public function resetPasswordConfirm(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => 'required|string|min:4|confirmed',
        ]);

        $token = $request->token;
        $email = $request->email;
        $password = $request->password;

        // Buscar el token en la tabla password_reset_tokens
        $resetRecord = DB::table('password_reset_tokens')
            ->where('email', $email)
            ->first();

        if (!$resetRecord) {
            return response()->json([
                'message' => 'Token inválido'
            ], 400);
        }

        // Verificar si el token coincide
        if (!Hash::check($token, $resetRecord->token)) {
            return response()->json([
                'message' => 'Token inválido'
            ], 400);
        }

        // Verificar si el token ha expirado
        $expiresAt = Carbon::parse($resetRecord->created_at)
            ->addMinutes(config('auth.passwords.administradores.expire', 60));

        if (Carbon::now()->isAfter($expiresAt)) {
            return response()->json([
                'message' => 'El token ha expirado'
            ], 400);
        }

        // Buscar el usuario
        $user = Administrador::where('email', $email)->first();

        if (!$user) {
            return response()->json([
                'message' => 'Usuario no encontrado'
            ], 404);
        }

        // Actualizar la contraseña
        $user->password = Hash::make($password);
        $user->save();

        // Eliminar el token usado
        DB::table('password_reset_tokens')
            ->where('email', $email)
            ->delete();

        return response()->json([
            'message' => 'Contraseña restablecida exitosamente'
        ]);
    }
}
