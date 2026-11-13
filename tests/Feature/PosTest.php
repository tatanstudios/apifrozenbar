<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\Categoria;
use App\Models\ConfiguracionNegocio;
use App\Models\Inventario;
use App\Models\MetodoPago;
use App\Models\MovimientoCaja;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\TurnoCaja;
use App\Models\UnidadesMedida;
use App\Models\Venta;
use App\Services\CajaService;
use Database\Seeders\CajaSeeder;
use Database\Seeders\UnidadesMedidaSeeder;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PosTest extends TestCase
{
    protected bool $turnoSigueAlUsuario = true;

    private Caja $caja;
    private TurnoCaja $turno;
    private Producto $pan;
    private Producto $pastel;   // no lleva inventario
    private Producto $queso;    // se vende por peso

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CajaSeeder::class, UnidadesMedidaSeeder::class]);

        $this->caja = Caja::first();
        $unidad = UnidadesMedida::where('nombre', 'Unidad')->first();
        $libra  = UnidadesMedida::where('nombre', 'Libra')->first();

        $this->pan    = $this->producto(['nombre' => 'Pan francés', 'precio_venta' => 0.15, 'costo_unitario' => 0.05, 'unidad_medida_id' => $unidad->id], 100);
        $this->pastel = $this->producto(['nombre' => 'Decoración', 'precio_venta' => 10, 'costo_unitario' => 0, 'controla_stock' => false, 'unidad_medida_id' => $unidad->id]);
        $this->queso  = $this->producto(['nombre' => 'Queso', 'precio_venta' => 3, 'costo_unitario' => 2, 'permite_fraccion' => true, 'clase' => 'reventa', 'unidad_medida_id' => $libra->id], 10);

        $abre = $this->crearUsuario('cajero');
        $this->turno = CajaService::abrir($this->caja->id, 20, $abre->id);
    }

    private function producto(array $datos, ?float $stock = null): Producto
    {
        $p = Producto::create(array_merge(['tipo' => 'bien', 'clase' => 'producto_terminado'], $datos));
        if ($stock !== null) {
            Inventario::create(['producto_id' => $p->id, 'stock_actual' => $stock, 'stock_minimo' => 0]);
        }
        return $p;
    }

    private function metodo(string $nombre): int
    {
        return MetodoPago::where('nombre', $nombre)->value('id');
    }

    private function vender(array $items, array $pagos, array $extra = [])
    {
        return $this->postJson('/api/pos/ventas', array_merge([
            'uuid'    => (string) Str::uuid(),
            'caja_id' => $this->caja->id,
            'items'   => $items,
            'pagos'   => $pagos,
        ], $extra));
    }

    private function efectivo(float $monto): array
    {
        return [['metodo_pago_id' => $this->metodo('Efectivo'), 'monto' => $monto]];
    }

    private function stock(Producto $p): float
    {
        return (float) Inventario::where('producto_id', $p->id)->value('stock_actual');
    }

    private function esperado(): float
    {
        return CajaService::resumen($this->turno->fresh())['efectivo_esperado'];
    }

    // ── Venta ───────────────────────────────────────────────────

    public function test_venta_en_efectivo_calcula_totales_cambio_stock_y_caja(): void
    {
        $this->actuarComo('cajero');

        $res = $this->vender(
            [['producto_id' => $this->pan->id, 'cantidad' => 10], ['producto_id' => $this->pastel->id, 'cantidad' => 1]],
            $this->efectivo(20)
        )->assertCreated();

        $res->assertJsonPath('venta.numero', '000001')
            ->assertJsonPath('venta.total', 11.5)
            ->assertJsonPath('venta.cambio', 8.5)
            ->assertJsonPath('venta.base_imponible', 11.5)    // sin IVA: lo cobrado es la venta
            ->assertJsonPath('venta.impuesto', 0)
            ->assertJsonPath('venta.pagos.0.recibido', 20)
            ->assertJsonPath('venta.pagos.0.monto', 11.5);

        $this->assertEquals(90, $this->stock($this->pan));
        $this->assertEquals(31.5, $this->esperado());          // fondo 20 + venta 11.50 (no el billete de 20)
        $this->assertEquals(0.5, (float) Venta::first()->costo_total);
        $this->assertDatabaseHas('movimientos_inventario', ['producto_id' => $this->pan->id, 'tipo' => 'venta', 'referencia_tipo' => 'venta']);
    }

    public function test_el_mismo_uuid_no_duplica_la_venta(): void
    {
        $this->actuarComo('cajero');
        $uuid = (string) Str::uuid();
        $items = [['producto_id' => $this->pan->id, 'cantidad' => 2]];

        $this->vender($items, $this->efectivo(1), ['uuid' => $uuid])->assertCreated();
        $this->vender($items, $this->efectivo(1), ['uuid' => $uuid])->assertOk()->assertJsonPath('venta.numero', '000001');

        $this->assertSame(1, Venta::count());
        $this->assertEquals(98, $this->stock($this->pan));
    }

    public function test_pago_combinado_tarjeta_y_efectivo(): void
    {
        $this->actuarComo('cajero');

        $this->vender([['producto_id' => $this->pastel->id, 'cantidad' => 1]], [
            ['metodo_pago_id' => $this->metodo('Tarjeta'), 'monto' => 4],
            ['metodo_pago_id' => $this->metodo('Efectivo'), 'monto' => 10],
        ])->assertCreated()
            ->assertJsonPath('venta.cambio', 4)
            ->assertJsonCount(2, 'venta.pagos');

        // A la gaveta solo entran los $6 de efectivo que se aplicaron
        $this->assertEquals(26, $this->esperado());
    }

    public function test_pagos_invalidos_se_rechazan(): void
    {
        $this->actuarComo('cajero');
        $item = [['producto_id' => $this->pastel->id, 'cantidad' => 1]];

        $this->vender($item, $this->efectivo(9.99))->assertStatus(422)->assertJsonPath('message', 'Pago incompleto: faltan $0.01');
        $this->vender($item, [['metodo_pago_id' => $this->metodo('Tarjeta'), 'monto' => 12]])->assertStatus(422);
        $this->vender($item, [['metodo_pago_id' => $this->metodo('Transferencia'), 'monto' => 10]])->assertStatus(422);
        $this->vender($item, [['metodo_pago_id' => $this->metodo('Transferencia'), 'monto' => 10, 'referencia' => 'TR-9']])->assertCreated();

        $this->assertSame(1, Venta::count());
    }

    public function test_no_se_vende_sin_turno_abierto(): void
    {
        $this->actuarComo('cajero');
        $this->turno->update(['estado' => 'cerrado']);

        $this->vender([['producto_id' => $this->pan->id, 'cantidad' => 1]], $this->efectivo(1))
            ->assertStatus(422)
            ->assertJsonPath('message', 'No hay un turno abierto en esta caja. Abre el turno para vender.');
    }

    public function test_venta_por_peso_y_enteros(): void
    {
        $this->actuarComo('cajero');

        $this->vender([['producto_id' => $this->queso->id, 'cantidad' => 1.5]], $this->efectivo(4.5))
            ->assertCreated()->assertJsonPath('venta.total', 4.5);
        $this->vender([['producto_id' => $this->pan->id, 'cantidad' => 1.5]], $this->efectivo(1))->assertStatus(422);

        $this->assertEquals(8.5, $this->stock($this->queso));
    }

    public function test_producto_que_no_se_vende_se_rechaza(): void
    {
        $this->actuarComo('cajero');
        $this->pan->update(['se_vende' => false]);

        $this->vender([['producto_id' => $this->pan->id, 'cantidad' => 1]], $this->efectivo(1))->assertStatus(422);
    }

    public function test_sin_stock_y_sin_permitir_negativo_no_queda_nada_a_medias(): void
    {
        ConfiguracionNegocio::actual()->update(['permitir_stock_negativo' => false]);
        $this->actuarComo('cajero');

        $this->vender([['producto_id' => $this->pan->id, 'cantidad' => 101]], $this->efectivo(20))->assertStatus(422);

        $this->assertSame(0, Venta::count());
        $this->assertSame(0, MovimientoCaja::count());
        $this->assertEquals(100, $this->stock($this->pan));
    }

    public function test_producto_que_permite_vender_sin_existencia_queda_en_negativo(): void
    {
        ConfiguracionNegocio::actual()->update(['permitir_stock_negativo' => false]);
        $unidad = UnidadesMedida::where('nombre', 'Unidad')->first();
        $coca = $this->producto(['nombre' => 'Coca-Cola', 'clase' => 'reventa', 'precio_venta' => 1, 'unidad_medida_id' => $unidad->id,
            'permite_sin_existencia' => true], 1);
        $this->actuarComo('cajero');

        $this->getJson('/api/pos/catalogo')->assertOk()
            ->assertJsonFragment(['nombre' => 'Coca-Cola', 'permite_sin_existencia' => true]);

        $this->vender([['producto_id' => $coca->id, 'cantidad' => 3]], $this->efectivo(3))->assertCreated();
        $this->assertEquals(-2, $this->stock($coca));

        // Los demás productos siguen bloqueados
        $this->vender([['producto_id' => $this->pan->id, 'cantidad' => 101]], $this->efectivo(20))->assertStatus(422);

        // Y la Coca-Cola solo se pasa al venderla: una merma o salida a mano sigue bloqueada
        $this->expectException(\App\Exceptions\NegocioException::class);
        \App\Services\InventarioService::aplicarMovimiento(productoId: $coca->id, tipo: 'merma', cantidad: 1, motivo: 'Prueba', usuarioId: 1);
    }

    // ── Descuentos ──────────────────────────────────────────────

    public function test_descuento_dentro_del_maximo_no_pide_autorizacion(): void
    {
        $this->actuarComo('cajero');

        $this->vender(
            [['producto_id' => $this->pastel->id, 'cantidad' => 1, 'descuento_tipo' => 'porcentaje', 'descuento_valor' => 10]],
            $this->efectivo(9)
        )->assertCreated()->assertJsonPath('venta.total', 9)->assertJsonPath('venta.descuento_total', 1);
    }

    public function test_descuento_mayor_al_maximo_solo_lo_hace_el_administrador_y_queda_registrado(): void
    {
        $admin = $this->crearUsuario('admin', ['nombre' => 'Gerente']);
        $this->actuarComo('cajero');
        $items = [['producto_id' => $this->pastel->id, 'cantidad' => 1]];
        $global = ['descuento_global' => ['tipo' => 'monto', 'valor' => 3]];

        $this->vender($items, $this->efectivo(7), $global)
            ->assertStatus(403)
            ->assertJsonPath('status', 'SIN_PERMISO')
            ->assertJsonPath('permiso', 'pos.descuento.autorizar');

        Sanctum::actingAs($admin, ['*'], 'sanctum');
        $this->vender($items, $this->efectivo(7), $global)
            ->assertCreated()
            ->assertJsonPath('venta.total', 7)
            ->assertJsonPath('venta.descuento_autorizado_por', 'Gerente');

        $this->assertDatabaseHas('auditorias', ['accion' => 'descuento_autorizado']);
    }

    // ── Anulación ───────────────────────────────────────────────

    public function test_anular_venta_solo_lo_hace_el_administrador_y_revierte_stock_y_caja(): void
    {
        $admin = $this->crearUsuario('admin');
        $this->actuarComo('cajero');
        $id = $this->vender([['producto_id' => $this->pan->id, 'cantidad' => 10]], $this->efectivo(5))->json('venta.id');

        $this->postJson("/api/pos/ventas/{$id}/anular", ['motivo' => 'Cobro equivocado'])
            ->assertStatus(403)->assertJsonPath('permiso', 'pos.anular');

        Sanctum::actingAs($admin, ['*'], 'sanctum');
        $this->postJson("/api/pos/ventas/{$id}/anular", ['motivo' => 'Cobro equivocado'])
            ->assertOk()->assertJsonPath('venta.estado', 'anulada');

        $this->assertEquals(100, $this->stock($this->pan));
        $this->assertEquals(20, $this->esperado());
        $this->assertDatabaseHas('auditorias', ['accion' => 'anular_venta', 'descripcion' => 'Cobro equivocado']);

        $this->postJson("/api/pos/ventas/{$id}/anular", ['motivo' => 'Otra vez'])->assertStatus(422);
    }

    public function test_no_se_anula_una_venta_de_un_turno_cerrado(): void
    {
        $this->actuarComo('admin');
        $id = $this->vender([['producto_id' => $this->pan->id, 'cantidad' => 1]], $this->efectivo(1))->json('venta.id');
        CajaService::cerrar($this->turno, null, 20.15, auth()->id());

        $this->postJson("/api/pos/ventas/{$id}/anular", ['motivo' => 'Tarde'])->assertStatus(422);
    }

    // ── Devoluciones ────────────────────────────────────────────

    public function test_devolucion_parcial_reparte_el_descuento_y_devuelve_stock(): void
    {
        $this->actuarComo('admin');
        // 10 panes a $0.15 con 10% de descuento global = $1.35 (cada pan efectivamente a $0.135)
        $venta = $this->vender(
            [['producto_id' => $this->pan->id, 'cantidad' => 10]],
            $this->efectivo(2),
            ['descuento_global' => ['tipo' => 'porcentaje', 'valor' => 10]]
        )->json('venta');
        $detalleId = $venta['detalles'][0]['id'];

        $devolver = fn (float $cantidad, bool $reingresa = true) => $this->postJson("/api/pos/ventas/{$venta['id']}/devoluciones", [
            'uuid' => (string) Str::uuid(), 'caja_id' => $this->caja->id, 'motivo' => 'Pan quemado',
            'items' => [['venta_detalle_id' => $detalleId, 'cantidad' => $cantidad, 'reingresa_inventario' => $reingresa]],
        ]);

        $devolver(4)->assertCreated()->assertJsonPath('devolucion.total', 0.54);
        $this->assertEquals(94, $this->stock($this->pan));
        $this->assertEquals(20.81, $this->esperado()); // 20 + 1.35 - 0.54

        $devolver(7)->assertStatus(422);                // solo quedan 6
        $devolver(6, false)->assertCreated()->assertJsonPath('venta.estado', 'devuelta');
        $this->assertEquals(94, $this->stock($this->pan)); // lo dañado no reingresa
    }

    public function test_cajero_no_puede_devolver(): void
    {
        $this->actuarComo('cajero');
        $venta = $this->vender([['producto_id' => $this->pan->id, 'cantidad' => 2]], $this->efectivo(1))->json('venta');

        $this->postJson("/api/pos/ventas/{$venta['id']}/devoluciones", [
            'uuid' => (string) Str::uuid(), 'caja_id' => $this->caja->id, 'motivo' => 'x',
            'items' => [['venta_detalle_id' => $venta['detalles'][0]['id'], 'cantidad' => 1]],
        ])->assertStatus(403)->assertJsonPath('status', 'SIN_PERMISO');
    }

    // ── Precuentas ──────────────────────────────────────────────

    public function test_precuenta_se_guarda_se_cobra_una_sola_vez_y_se_reabre_al_anular(): void
    {
        $this->actuarComo('admin');

        $pedido = $this->postJson('/api/pos/pedidos', [
            'uuid' => (string) Str::uuid(), 'nombre' => 'Mesa 2', 'caja_id' => $this->caja->id,
            'items' => [['producto_id' => $this->pan->id, 'cantidad' => 4, 'notas' => 'tostado']],
        ])->assertCreated()->assertJsonPath('pedido.total', 0.6)->json('pedido');

        // Guardar la precuenta no mueve inventario
        $this->assertEquals(100, $this->stock($this->pan));
        $this->assertCount(1, $this->getJson('/api/pos/pedidos')->json('pedidos'));

        $venta = $this->vender([['producto_id' => $this->pan->id, 'cantidad' => 4]], $this->efectivo(1), ['pedido_id' => $pedido['id']])
            ->assertCreated()->json('venta');
        $this->assertSame('cobrado', Pedido::find($pedido['id'])->estado);
        $this->assertCount(0, $this->getJson('/api/pos/pedidos')->json('pedidos'));

        $this->vender([['producto_id' => $this->pan->id, 'cantidad' => 4]], $this->efectivo(1), ['pedido_id' => $pedido['id']])
            ->assertStatus(422);

        $this->postJson("/api/pos/ventas/{$venta['id']}/anular", ['motivo' => 'Error'])->assertOk();
        $this->assertSame('abierto', Pedido::find($pedido['id'])->estado);
    }

    public function test_cancelar_precuenta_con_motivo(): void
    {
        $this->actuarComo('cajero');
        $id = $this->postJson('/api/pos/pedidos', [
            'uuid' => (string) Str::uuid(), 'nombre' => 'Sra. Ana',
            'items' => [['producto_id' => $this->pan->id, 'cantidad' => 1]],
        ])->json('pedido.id');

        $this->postJson("/api/pos/pedidos/{$id}/cancelar", [])->assertStatus(422);
        $this->postJson("/api/pos/pedidos/{$id}/cancelar", ['motivo' => 'Se fue'])->assertOk();
        $this->assertSame('cancelado', Pedido::find($id)->estado);
    }

    // ── Catálogo e historial ────────────────────────────────────

    public function test_catalogo_trae_solo_lo_que_se_vende_y_sus_categorias(): void
    {
        $panes = Categoria::create(['nombre' => 'Panes', 'estado' => true]);
        Categoria::create(['nombre' => 'Vacía', 'estado' => true]);
        $this->pan->categorias()->sync([$panes->id]);
        $this->producto(['nombre' => 'Harina', 'precio_venta' => 0, 'se_vende' => false, 'clase' => 'materia_prima', 'unidad_medida_id' => $this->pan->unidad_medida_id], 50);

        $this->actuarComo('cajero');
        $res = $this->getJson('/api/pos/catalogo')->assertOk();

        $this->assertEqualsCanonicalizing(['Pan francés', 'Decoración', 'Queso'], array_column($res->json('productos'), 'nombre'));
        $this->assertSame(['Panes'], array_column($res->json('categorias'), 'nombre'));
    }

    public function test_historial_por_turno_excluye_anuladas_del_total(): void
    {
        $this->actuarComo('admin');
        $this->vender([['producto_id' => $this->pastel->id, 'cantidad' => 1]], $this->efectivo(10));
        $id = $this->vender([['producto_id' => $this->pastel->id, 'cantidad' => 2]], $this->efectivo(20))->json('venta.id');
        $this->postJson("/api/pos/ventas/{$id}/anular", ['motivo' => 'Error'])->assertOk();

        $this->getJson("/api/pos/ventas?turno_id={$this->turno->id}")
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('totales.cantidad', 1)
            ->assertJsonPath('totales.total', 10);
    }

    public function test_ventas_aparecen_por_empleado_en_el_turno(): void
    {
        $this->actuarComo('cajero', ['nombre' => 'Ana']);
        $this->vender([['producto_id' => $this->pastel->id, 'cantidad' => 1]], $this->efectivo(10))->assertCreated();

        $resumen = CajaService::resumen($this->turno);
        $this->assertSame('Ana', $resumen['ventas_por_usuario'][0]['usuario']);
        $this->assertEquals(10, $resumen['totales']['ventas']);
    }
}
