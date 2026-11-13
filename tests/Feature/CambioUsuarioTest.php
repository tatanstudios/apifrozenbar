<?php

namespace Tests\Feature;

use App\Models\Administrador;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

// Una sola tablet para varios meseros: en la pantalla bloqueada, el PIN abre la sesión de su dueño
class CambioUsuarioTest extends TestCase
{
    private Administrador $mesero1;
    private Administrador $mesero2;
    private string $token1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mesero1 = $this->crearUsuario('mesero', ['nombre' => 'Mesero 1', 'pin' => '1111']);
        $this->mesero2 = $this->crearUsuario('mesero', ['nombre' => 'Mesero 2', 'pin' => '2222']);
        $this->token1 = $this->mesero1->createToken('tablet-meseros')->plainTextToken;
    }

    private function cambiar(string $pin, ?string $token = null)
    {
        return $this->withToken($token ?? $this->token1)->postJson('/api/me/cambiar-usuario', ['pin' => $pin]);
    }

    public function test_el_pin_de_otro_mesero_abre_su_sesion_y_cierra_la_anterior(): void
    {
        $token2 = $this->cambiar('2222')->assertOk()->assertJsonPath('cambio', true)->json('token');

        $this->app['auth']->forgetGuards();
        $this->withToken($token2)->getJson('/api/me')->assertOk()->assertJsonPath('nombre', 'Mesero 2');

        // El token anterior ya no sirve y el nuevo no vence (tablet)
        $this->assertSame(0, $this->mesero1->tokens()->count());
        $this->assertNull(PersonalAccessToken::findToken($token2)->expires_at);
        $this->assertSame('tablet-meseros', PersonalAccessToken::findToken($token2)->name);
    }

    public function test_el_mismo_pin_solo_desbloquea(): void
    {
        $this->cambiar('1111')->assertOk()->assertJsonPath('cambio', false)->assertJsonMissingPath('token');
        $this->assertSame(1, $this->mesero1->tokens()->count());
    }

    public function test_pin_incorrecto_o_de_usuario_desactivado(): void
    {
        $this->cambiar('9999')->assertStatus(422)->assertJsonPath('message', 'PIN incorrecto');

        $this->mesero2->update(['estado' => false]);
        $this->cambiar('2222')->assertStatus(422);
        $this->assertSame(1, $this->mesero1->tokens()->count());
    }

    public function test_bloquea_tras_25_intentos_fallidos(): void
    {
        foreach (range(1, 25) as $_) {
            $this->cambiar('0000')->assertStatus(422);
        }
        $this->cambiar('2222')->assertStatus(429)->assertJsonStructure(['segundos']);
    }
}
