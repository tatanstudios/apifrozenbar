<?php

namespace Tests\Feature;

use App\Models\Administrador;
use Tests\TestCase;

// Una tablet registrada (alguien entró una vez con correo y contraseña) deja entrar con PIN
class LoginPinTest extends TestCase
{
    private Administrador $mesero1;
    private Administrador $mesero2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mesero1 = $this->crearUsuario('mesero', ['nombre' => 'Mesero 1', 'pin' => '1111']);
        $this->mesero2 = $this->crearUsuario('mesero', ['nombre' => 'Mesero 2', 'pin' => '2222']);
    }

    private function registrarTablet(): string
    {
        return $this->postJson('/api/login', [
            'email' => $this->mesero1->email, 'password' => 'secreto123',
            'device_name' => 'tablet-meseros', 'registrar_dispositivo' => true,
        ])->assertOk()->json('dispositivo');
    }

    public function test_tablet_registrada_entra_con_el_pin_de_cualquier_usuario(): void
    {
        $llave = $this->registrarTablet();
        $this->assertNotEmpty($llave);

        $token = $this->postJson('/api/login-pin', ['dispositivo' => $llave, 'pin' => '2222'])
            ->assertOk()->assertJsonPath('user.nombre', 'Mesero 2')->json('token');

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/me')->assertOk()->assertJsonPath('nombre', 'Mesero 2');
    }

    public function test_la_llave_de_la_tablet_no_sirve_como_sesion(): void
    {
        $llave = $this->registrarTablet();

        $this->app['auth']->forgetGuards();
        $this->withToken($llave)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_sin_tablet_registrada_no_se_acepta_el_pin(): void
    {
        // Un login normal (web) no da llave, y un token de sesión no sirve como llave
        $sesion = $this->postJson('/api/login', ['email' => $this->mesero1->email, 'password' => 'secreto123'])
            ->assertOk()->assertJsonMissingPath('dispositivo')->json('token');

        $this->postJson('/api/login-pin', ['dispositivo' => $sesion, 'pin' => '1111'])
            ->assertUnauthorized()->assertJsonPath('status', 'DISPOSITIVO_NO_REGISTRADO');
        $this->postJson('/api/login-pin', ['dispositivo' => 'cualquiera', 'pin' => '1111'])->assertUnauthorized();
    }

    public function test_muchas_entradas_correctas_seguidas_no_se_bloquean(): void
    {
        $llave = $this->registrarTablet();

        // Meseros turnándose la tablet: solo cuentan los PIN incorrectos
        foreach (range(1, 20) as $n) {
            $this->postJson('/api/login-pin', ['dispositivo' => $llave, 'pin' => $n % 2 ? '1111' : '2222'])->assertOk();
            $this->app['auth']->forgetGuards();
        }
        foreach (range(1, 8) as $_) {
            $this->postJson('/api/login', ['email' => $this->mesero2->email, 'password' => 'secreto123'])->assertOk();
        }
    }

    public function test_pin_incorrecto_y_bloqueo_por_intentos(): void
    {
        $llave = $this->registrarTablet();

        foreach (range(1, 25) as $_) {
            $this->postJson('/api/login-pin', ['dispositivo' => $llave, 'pin' => '0000'])->assertStatus(422);
        }
        $this->postJson('/api/login-pin', ['dispositivo' => $llave, 'pin' => '2222'])->assertStatus(429);
    }

    public function test_desactivar_a_quien_registro_la_tablet_la_desregistra(): void
    {
        $llave = $this->registrarTablet();
        $this->mesero1->update(['estado' => false]);

        $this->postJson('/api/login-pin', ['dispositivo' => $llave, 'pin' => '2222'])
            ->assertUnauthorized()->assertJsonPath('status', 'DISPOSITIVO_NO_REGISTRADO');
    }
}
