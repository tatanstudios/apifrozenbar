<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use Tests\TestCase;

class LoginTest extends TestCase
{
    public function test_usuario_activo_inicia_sesion_y_recibe_token(): void
    {
        $usuario = $this->crearUsuario('cajero', ['email' => 'cajero@test.com']);

        $this->postJson('/api/login', ['email' => 'cajero@test.com', 'password' => 'secreto123'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['token'])
            ->assertJsonMissingPath('user.password')
            ->assertJsonMissingPath('user.pin');

        $this->assertNotNull($usuario->fresh()->ultimo_acceso);
        $this->assertDatabaseHas('auditorias', ['modulo' => 'auth', 'accion' => 'login', 'usuario_id' => $usuario->id]);
    }

    public function test_la_sesion_web_y_la_de_la_tableta_no_vencen_hasta_cerrar_sesion(): void
    {
        $this->crearUsuario('cajero', ['email' => 'cajero@test.com']);

        $web = $this->postJson('/api/login', ['email' => 'cajero@test.com', 'password' => 'secreto123', 'device_name' => 'Web Application'])->json('token');
        $tablet = $this->postJson('/api/login', ['email' => 'cajero@test.com', 'password' => 'secreto123', 'device_name' => 'tablet-meseros'])->json('token');

        // Un mes después las dos siguen abiertas
        $this->travel(30)->days();
        $this->app['auth']->forgetGuards();
        $this->withToken($web)->getJson('/api/me')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($tablet)->getJson('/api/me')->assertOk();

        // Solo «Cerrar sesión» la termina
        $this->app['auth']->forgetGuards();
        $this->withToken($web)->postJson('/api/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($web)->getJson('/api/me')->assertStatus(401);
    }

    public function test_credenciales_invalidas_usan_el_mismo_mensaje(): void
    {
        $this->crearUsuario('cajero', ['email' => 'cajero@test.com']);

        $correoInexistente = $this->postJson('/api/login', ['email' => 'nadie@test.com', 'password' => 'secreto123']);
        $claveIncorrecta   = $this->postJson('/api/login', ['email' => 'cajero@test.com', 'password' => 'otra']);

        $correoInexistente->assertStatus(401)->assertJsonPath('status', 'INVALID_CREDENTIALS');
        $claveIncorrecta->assertStatus(401)->assertJsonPath('status', 'INVALID_CREDENTIALS');
        $this->assertSame($correoInexistente->json('message'), $claveIncorrecta->json('message'));
        $this->assertSame(2, Auditoria::where('accion', 'login_fallido')->count());
    }

    public function test_usuario_desactivado_no_puede_iniciar_sesion(): void
    {
        $this->crearUsuario('cajero', ['email' => 'inactivo@test.com', 'estado' => false]);

        $this->postJson('/api/login', ['email' => 'inactivo@test.com', 'password' => 'secreto123'])
            ->assertStatus(403)
            ->assertJsonPath('status', 'USER_INACTIVE');
    }

    public function test_token_de_usuario_desactivado_deja_de_funcionar(): void
    {
        $usuario = $this->crearUsuario('cajero');
        $token   = $usuario->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/me')->assertOk();

        $usuario->update(['estado' => false]);
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/me')
            ->assertStatus(401)
            ->assertJsonPath('status', 'USER_INACTIVE');
    }

    public function test_login_limita_intentos(): void
    {
        $this->crearUsuario('cajero', ['email' => 'cajero@test.com']);

        for ($i = 0; $i < 25; $i++) {
            $this->postJson('/api/login', ['email' => 'cajero@test.com', 'password' => 'mala'])->assertStatus(401);
        }

        $this->postJson('/api/login', ['email' => 'cajero@test.com', 'password' => 'secreto123'])
            ->assertStatus(429);
    }
}
