<?php

namespace Tests\Feature;

use App\Exceptions\AutorizacionRequeridaException;
use App\Models\Administrador;
use App\Services\AutorizacionService;
use App\Services\CorrelativoService;
use Tests\TestCase;

class FundamentosTest extends TestCase
{
    public function test_correlativo_es_consecutivo_y_con_prefijo(): void
    {
        $this->assertSame('000001', CorrelativoService::siguiente('venta'));
        $this->assertSame('000002', CorrelativoService::siguiente('venta'));
        $this->assertSame('OC-000001', CorrelativoService::siguiente('orden_compra'));
    }

    public function test_correlativo_nuevo_se_crea_al_vuelo(): void
    {
        $this->assertSame('X-000001', CorrelativoService::siguiente('nuevo_tipo', 'X'));
        $this->assertSame('X-000002', CorrelativoService::siguiente('nuevo_tipo', 'X'));
    }

    public function test_cualquier_usuario_lee_configuracion_pero_solo_admin_la_edita(): void
    {
        $this->actuarComo('cajero');

        $this->getJson('/api/configuracion/negocio')
            ->assertOk()
            ->assertJsonPath('data.nombre', 'The Frozen Bar');

        $this->postJson('/api/configuracion/negocio', [])->assertStatus(403);
    }

    public function test_admin_actualiza_configuracion_y_queda_auditado(): void
    {
        $this->actuarComo('admin');

        $this->postJson('/api/configuracion/negocio', [
            'nombre'                         => 'The Frozen Bar',
            'telefono'                       => '2222-0000',
            'moneda'                         => 'USD',
            'simbolo_moneda'                 => '$',
            'permitir_stock_negativo'        => false,
            'descuento_max_sin_autorizacion' => 15,
            'cierre_ciego'                   => true,
            'aviso_cambio'                   => false,
        ])->assertOk()->assertJsonPath('data.permitir_stock_negativo', false)->assertJsonPath('data.aviso_cambio', false);

        $this->assertDatabaseHas('configuracion_negocio', ['telefono' => '2222-0000', 'descuento_max_sin_autorizacion' => 15, 'cierre_ciego' => true, 'aviso_cambio' => false]);
        $this->assertDatabaseHas('auditorias', ['modulo' => 'configuracion', 'accion' => 'actualizar']);
    }

    public function test_guardar_negocio_desde_la_tablet_sin_cambiar_el_logo(): void
    {
        $this->actuarComo('admin');
        \App\Models\ConfiguracionNegocio::actual()->update(['logo' => 'logo_actual.png']);

        // La tablet manda lo que leyó, con el nombre del logo como texto: no es un cambio de logo
        $this->postJson('/api/configuracion/negocio', [
            'nombre' => 'The Frozen Bar', 'telefono' => '2222-1111', 'logo' => 'logo_actual.png',
            'moneda' => 'USD', 'simbolo_moneda' => '$', 'permitir_stock_negativo' => false, 'descuento_max_sin_autorizacion' => 10,
        ])->assertOk();

        $this->assertDatabaseHas('configuracion_negocio', ['telefono' => '2222-1111', 'logo' => 'logo_actual.png']);
    }

    public function test_autoriza_quien_tiene_el_permiso_o_su_pin(): void
    {
        $mesero = $this->crearUsuario('mesero');
        $admin = $this->crearUsuario('admin', ['pin' => '9876']);
        $this->assertSame($admin->id, AutorizacionService::autorizar($admin, 'pos.anular', 'Anular')->id);

        // El mesero no puede solo, pero sí con el PIN del administrador
        request()->merge(['autorizacion' => '9876']);
        $this->assertSame($admin->id, AutorizacionService::autorizar($mesero, 'pos.anular', 'Anular')->id);

        request()->merge(['autorizacion' => null]);
        $this->expectException(AutorizacionRequeridaException::class);
        AutorizacionService::autorizar($mesero, 'pos.anular', 'Anular');
    }

    public function test_crear_usuario_no_pide_pin(): void
    {
        $this->actuarComo('admin');

        $this->postJson('/api/admin/permisos/nuevo-usuario', [
            'nombre' => 'Ana', 'email' => 'ana@test.com', 'password' => 'secreto123', 'rol' => 'mesero',
        ])->assertCreated();

        $this->assertTrue(Administrador::where('email', 'ana@test.com')->first()->hasRole('mesero'));
    }

    public function test_desactivar_usuario_revoca_sus_tokens(): void
    {
        $this->actuarComo('admin');
        $cajero = $this->crearUsuario('cajero');
        $cajero->createToken('tablet');
        $rolCajero = $cajero->roles()->first();

        $this->putJson("/api/admin/actualizar/administrador/{$cajero->id}", [
            'nombre' => $cajero->nombre,
            'email'  => $cajero->email,
            'rol'    => $rolCajero->id,
            'estado' => false,
        ])->assertOk();

        $this->assertSame(0, $cajero->tokens()->count());
        $this->assertDatabaseHas('auditorias', ['modulo' => 'usuarios', 'accion' => 'actualizar', 'entidad_id' => $cajero->id]);
    }
}
