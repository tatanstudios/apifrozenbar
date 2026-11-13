<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\ConfiguracionNegocio;
use App\Models\Inventario;
use App\Models\MetodoPago;
use App\Models\Producto;
use App\Models\TurnoCaja;
use App\Models\UnidadesMedida;
use App\Models\Venta;
use App\Services\CajaService;
use Database\Seeders\CajaSeeder;
use Database\Seeders\UnidadesMedidaSeeder;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncTest extends TestCase
{
    private Caja $caja;
    private TurnoCaja $turno;
    private Producto $pan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CajaSeeder::class, UnidadesMedidaSeeder::class]);

        $this->caja = Caja::first();
        $this->pan = Producto::create([
            'nombre' => 'Pan francés', 'tipo' => 'bien', 'clase' => 'producto_terminado',
            'precio_venta' => 0.15, 'costo_unitario' => 0.05,
            'unidad_medida_id' => UnidadesMedida::where('nombre', 'Unidad')->value('id'),
        ]);
        Inventario::create(['producto_id' => $this->pan->id, 'stock_actual' => 20]);
        $this->turno = CajaService::abrir($this->caja->id, 20, $this->crearUsuario('cajero')->id);
    }

    private function ventaOffline(array $cambios = []): array
    {
        return array_merge([
            'uuid'               => (string) Str::uuid(),
            'caja_id'            => $this->caja->id,
            'turno_id'           => $this->turno->id,
            'numero_provisional' => 'OFF-1-000001',
            'fecha_venta'        => now()->subMinutes(30)->toIso8601String(),
            'items'              => [['producto_id' => $this->pan->id, 'cantidad' => 10, 'precio_unitario' => 0.15]],
            'pagos'              => [['metodo_pago_id' => MetodoPago::where('nombre', 'Efectivo')->value('id'), 'monto' => 2]],
        ], $cambios);
    }

    private function sync(array ...$ventas)
    {
        return $this->postJson('/api/pos/sync', ['ventas' => $ventas])->assertOk();
    }

    public function test_venta_offline_se_registra_con_hora_del_dispositivo_y_numero_real(): void
    {
        $this->actuarComo('cajero');
        $datos = $this->ventaOffline();

        $this->sync($datos)
            ->assertJsonPath('resultados.0.estado', 'creada')
            ->assertJsonPath('resultados.0.numero', '000001')
            ->assertJsonPath('resultados.0.conflictos', []);

        $venta = Venta::first();
        $this->assertSame('offline', $venta->origen);
        $this->assertSame('OFF-1-000001', $venta->numero_provisional);
        $this->assertNotNull($venta->sincronizada_at);
        $this->assertTrue($venta->fecha_venta->lt(now()->subMinutes(29)));
        $this->assertEquals(10, (float) Inventario::where('producto_id', $this->pan->id)->value('stock_actual'));
        $this->assertEquals(21.5, CajaService::resumen($this->turno)['efectivo_esperado']);
    }

    public function test_hora_utc_del_dispositivo_se_guarda_en_hora_local(): void
    {
        $this->actuarComo('cajero');
        $hora = now()->subMinutes(30)->startOfMinute();

        // El navegador manda toISOString(): UTC con "Z"
        $this->sync($this->ventaOffline(['fecha_venta' => $hora->copy()->utc()->format('Y-m-d\TH:i:s.v\Z')]));

        $this->assertSame($hora->format('Y-m-d H:i'), Venta::first()->fecha_venta->format('Y-m-d H:i'));
    }

    public function test_reenviar_el_lote_no_duplica(): void
    {
        $this->actuarComo('cajero');
        $datos = $this->ventaOffline();

        $this->sync($datos);
        $this->sync($datos)->assertJsonPath('resultados.0.estado', 'ya_existia');

        $this->assertSame(1, Venta::count());
    }

    public function test_se_respeta_el_precio_cobrado_y_se_anota_el_cambio(): void
    {
        $this->actuarComo('cajero');
        $this->pan->update(['precio_venta' => 0.20]); // subió mientras no había conexión

        $res = $this->sync($this->ventaOffline());

        $this->assertEquals(1.5, (float) Venta::first()->total);
        $this->assertStringContainsString('se cobró a $0.15 y el precio actual es $0.20', $res->json('resultados.0.conflictos.0'));
        $this->assertDatabaseHas('auditorias', ['accion' => 'venta_offline_conflicto']);
    }

    public function test_sin_stock_se_acepta_igual_y_queda_anotado(): void
    {
        ConfiguracionNegocio::actual()->update(['permitir_stock_negativo' => false]);
        $this->actuarComo('cajero');

        $res = $this->sync($this->ventaOffline([
            'items' => [['producto_id' => $this->pan->id, 'cantidad' => 25, 'precio_unitario' => 0.15]],
            'pagos' => [['metodo_pago_id' => MetodoPago::where('nombre', 'Efectivo')->value('id'), 'monto' => 5]],
        ]));

        $res->assertJsonPath('resultados.0.estado', 'creada');
        $this->assertStringContainsString('no había existencia suficiente (quedó en -5)', $res->json('resultados.0.conflictos.0'));
        $this->assertEquals(-5, (float) Inventario::where('producto_id', $this->pan->id)->value('stock_actual'));
    }

    public function test_producto_desactivado_mientras_tanto_se_acepta(): void
    {
        $this->actuarComo('cajero');
        $this->pan->update(['activo' => false]);

        $res = $this->sync($this->ventaOffline());

        $res->assertJsonPath('resultados.0.estado', 'creada');
        $this->assertStringContainsString('ya no estaba a la venta', $res->json('resultados.0.conflictos.0'));
    }

    public function test_turno_cerrado_pasa_al_turno_abierto_actual(): void
    {
        $usuario = $this->actuarComo('admin');
        $original = $this->turno->id;
        CajaService::cerrar($this->turno, null, 20, $usuario->id);
        $nuevo = CajaService::abrir($this->caja->id, 30, $usuario->id);

        $res = $this->sync($this->ventaOffline());

        $this->assertSame($nuevo->id, Venta::first()->turno_id);
        $this->assertStringContainsString("El turno #{$original} ya estaba cerrado", $res->json('resultados.0.conflictos.0'));
    }

    public function test_sin_turno_abierto_queda_pendiente_y_las_demas_se_guardan(): void
    {
        $usuario = $this->actuarComo('admin');
        $otraCaja = Caja::create(['nombre' => 'Caja 2', 'activo' => true]);
        CajaService::abrir($otraCaja->id, 0, $usuario->id);
        CajaService::cerrar($this->turno, null, 20, $usuario->id);

        $res = $this->sync(
            $this->ventaOffline(),
            $this->ventaOffline(['caja_id' => $otraCaja->id, 'turno_id' => null, 'numero_provisional' => 'OFF-2-000001'])
        );

        $res->assertJsonPath('resultados.0.estado', 'error')
            ->assertJsonPath('resultados.0.message', 'No hay turno abierto en la caja: la venta queda pendiente hasta que se abra uno')
            ->assertJsonPath('resultados.1.estado', 'creada');
        $this->assertSame(1, Venta::count());
    }

    public function test_descuento_sin_permiso_se_acepta_y_queda_anotado(): void
    {
        $this->actuarComo('cajero');

        $res = $this->sync($this->ventaOffline([
            'descuento_global' => ['tipo' => 'porcentaje', 'valor' => 50],
        ]));

        $res->assertJsonPath('resultados.0.estado', 'creada');
        $this->assertStringContainsString('sin autorización válida', $res->json('resultados.0.conflictos.0'));
        $this->assertEquals(0.75, (float) Venta::first()->total);
    }

    public function test_descuento_del_administrador_queda_autorizado(): void
    {
        $this->actuarComo('admin', ['nombre' => 'Gerente']);

        $this->sync($this->ventaOffline([
            'descuento_global' => ['tipo' => 'porcentaje', 'valor' => 50],
        ]))->assertJsonPath('resultados.0.conflictos', []);

        $this->assertNotNull(Venta::first()->descuento_autorizado_por);
    }

    public function test_venta_con_datos_invalidos_no_detiene_el_lote(): void
    {
        $this->actuarComo('cajero');

        $this->sync(
            $this->ventaOffline(['fecha_venta' => now()->addDay()->toIso8601String()]),
            $this->ventaOffline(['pagos' => [['metodo_pago_id' => MetodoPago::where('nombre', 'Efectivo')->value('id'), 'monto' => 1]]]),
            $this->ventaOffline(),
        )
            ->assertJsonPath('resultados.0.estado', 'error')
            ->assertJsonPath('resultados.1.estado', 'error')
            ->assertJsonPath('resultados.1.message', 'Pago incompleto: faltan $0.50')
            ->assertJsonPath('resultados.2.estado', 'creada');
    }

    public function test_sync_y_ping_requieren_permiso_de_vender(): void
    {
        $this->actuarComo('encargado_inventario');
        $this->getJson('/api/pos/ping')->assertStatus(403);
        $this->postJson('/api/pos/sync', ['ventas' => [$this->ventaOffline()]])->assertStatus(403);

        $this->actuarComo('cajero');
        $this->getJson('/api/pos/ping')->assertOk();
    }

    public function test_venta_offline_con_extras_respeta_su_precio_y_los_muestra(): void
    {
        $this->actuarComo('admin');
        $this->pan->update(['controla_stock' => true, 'se_vende' => true, 'activo' => true]);
        $grupo = \App\Models\GrupoModificador::create(['nombre' => 'Relleno']);
        $grupo->productos()->attach($this->pan->id);
        $queso = \App\Models\Modificador::create(['grupo_id' => $grupo->id, 'nombre' => 'Queso', 'precio' => 0.10]);

        // 10 panes con queso a $0.25 c/u: sin conflicto de precio, el nombre lleva el extra y la nota
        $this->sync($this->ventaOffline(['items' => [[
            'producto_id' => $this->pan->id, 'cantidad' => 10, 'precio_unitario' => 0.25,
            'modificadores' => [$queso->id], 'notas' => 'tostados',
        ]], 'pagos' => [['metodo_pago_id' => MetodoPago::where('nombre', 'Efectivo')->value('id'), 'monto' => 3]]]))
            ->assertJsonPath('resultados.0.estado', 'creada')->assertJsonPath('resultados.0.conflictos', []);
        $this->assertSame('Pan francés (Queso) · tostados', Venta::first()->detalles()->first()->nombre_producto);

        // Si el extra ya no existe al sincronizar, la venta entra igual y queda el aviso
        $queso->update(['activo' => false]);
        $res = $this->sync($this->ventaOffline(['numero_provisional' => 'OFF-1-000002', 'items' => [[
            'producto_id' => $this->pan->id, 'cantidad' => 1, 'precio_unitario' => 0.25, 'modificadores' => [$queso->id],
        ]]]))->assertJsonPath('resultados.0.estado', 'creada');
        $this->assertStringContainsString('se registró sin extras', implode(' ', $res->json('resultados.0.conflictos')));
    }
}
