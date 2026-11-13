<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\Compra;
use App\Models\CuentaPagar;
use App\Models\Inventario;
use App\Models\MetodoPago;
use App\Models\OrdenCompra;
use App\Models\PagoProveedor;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\TurnoCaja;
use App\Models\UnidadesMedida;
use App\Services\CajaService;
use Database\Seeders\CajaSeeder;
use Database\Seeders\UnidadesMedidaSeeder;
use Illuminate\Support\Str;
use Tests\TestCase;

class ComprasTest extends TestCase
{
    protected bool $turnoSigueAlUsuario = true;

    private Caja $caja;
    private TurnoCaja $turno;
    private Proveedor $proveedor;
    private Producto $harina;   // se maneja en libras y se compra por quintal
    private Producto $huevo;    // se maneja por unidad y se compra por docena
    private int $quintal;
    private int $docena;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CajaSeeder::class, UnidadesMedidaSeeder::class]);

        $this->caja = Caja::first();
        $unidad = UnidadesMedida::where('nombre', 'Unidad')->value('id');
        $libra = UnidadesMedida::where('nombre', 'Libra')->value('id');
        $this->quintal = UnidadesMedida::where('nombre', 'Quintal')->value('id');
        $this->docena = UnidadesMedida::where('nombre', 'Docena')->value('id');

        $this->harina = Producto::create([
            'nombre' => 'Harina', 'tipo' => 'bien', 'clase' => 'materia_prima', 'se_vende' => false,
            'permite_fraccion' => true, 'costo_unitario' => 0.40, 'unidad_medida_id' => $libra,
        ]);
        Inventario::create(['producto_id' => $this->harina->id, 'stock_actual' => 100]);

        $this->huevo = Producto::create([
            'nombre' => 'Huevo', 'tipo' => 'bien', 'clase' => 'materia_prima', 'se_vende' => false,
            'costo_unitario' => 0, 'unidad_medida_id' => $unidad,
        ]);

        $this->proveedor = Proveedor::create(['nombre' => 'Molinos S.A.', 'dias_credito' => 30, 'activo' => true]);
        $this->turno = CajaService::abrir($this->caja->id, 100, $this->crearUsuario('cajero')->id);
    }

    private function metodo(string $nombre): int
    {
        return MetodoPago::where('nombre', $nombre)->value('id');
    }

    private function stock(Producto $p): float
    {
        return (float) Inventario::where('producto_id', $p->id)->value('stock_actual');
    }

    private function comprar(array $extra = [])
    {
        return $this->postJson('/api/compras', array_merge([
            'uuid'             => (string) Str::uuid(),
            'proveedor_id'     => $this->proveedor->id,
            'fecha'            => today()->toDateString(),
            'tipo_documento'   => 'ccf',
            'numero_documento' => '00123',
            'condicion'        => 'credito',
            'items'            => [['producto_id' => $this->harina->id, 'unidad_medida_id' => $this->quintal, 'cantidad' => 1, 'costo_unitario' => 50]],
        ], $extra));
    }

    private function saldoProveedor(): float
    {
        return (float) $this->proveedor->fresh()->saldo;
    }

    // ── Proveedores ───────────────────────────────────────────

    public function test_crear_y_editar_proveedor(): void
    {
        $this->actuarComo('encargado_inventario');

        $id = $this->postJson('/api/compras/proveedores', [
            'nombre' => 'Distribuidora La Fe', 'nit' => '0614-010101-101-1', 'contacto' => 'Carlos', 'dias_credito' => 15,
        ])->assertCreated()->json('proveedor.id');

        $this->postJson('/api/compras/proveedores', ['nombre' => 'Otra', 'nit' => '0614-010101-101-1'])
            ->assertStatus(422)->assertJsonPath('message', 'Ya hay un proveedor con ese NIT');

        $this->putJson("/api/compras/proveedores/{$id}", ['nombre' => 'Distribuidora La Fe', 'dias_credito' => 30])
            ->assertOk()->assertJsonPath('proveedor.dias_credito', 30);

        $this->getJson('/api/compras/proveedores?buscar=Carlos')->assertJsonPath('proveedores.0.nombre', 'Distribuidora La Fe');
    }

    public function test_proveedor_con_campos_vacios_como_lo_manda_la_tablet(): void
    {
        $this->actuarComo('encargado_inventario');

        // La tablet manda null en todo lo que se deja vacío; días de crédito vacío = de contado
        $vacio = ['nit' => null, 'nrc' => null, 'contacto' => null, 'telefono' => null, 'correo' => null,
            'direccion' => null, 'dias_credito' => null, 'notas' => null, 'activo' => true];
        $id = $this->postJson('/api/compras/proveedores', ['nombre' => 'Supermercado', ...$vacio])
            ->assertCreated()->assertJsonPath('proveedor.dias_credito', 0)->json('proveedor.id');

        $this->putJson("/api/compras/proveedores/{$id}", ['nombre' => 'Supermercado La Colonia', ...$vacio])
            ->assertOk()->assertJsonPath('proveedor.dias_credito', 0);
    }

    // ── Compras ───────────────────────────────────────────────

    public function test_compra_al_credito_convierte_unidades_y_recalcula_costo(): void
    {
        $this->actuarComo('encargado_inventario');

        $this->comprar(['impuesto' => 6.50])->assertCreated()
            ->assertJsonPath('compra.numero', 'C-000001')
            ->assertJsonPath('compra.total', 56.5)
            ->assertJsonPath('compra.items.0.cantidad_base', 100)
            ->assertJsonPath('compra.items.0.costo_base', 0.5);

        // 100 lb a $0.40 + 100 lb a $0.50 = promedio $0.45
        $this->assertEquals(200, $this->stock($this->harina));
        $this->assertEquals(0.45, (float) $this->harina->fresh()->costo_unitario);

        $cuenta = CuentaPagar::first();
        $this->assertEquals(56.5, (float) $cuenta->saldo);
        $this->assertSame(today()->addDays(30)->toDateString(), $cuenta->fecha_vencimiento->toDateString());
        $this->assertEquals(56.5, $this->saldoProveedor());
        $this->assertDatabaseHas('movimientos_inventario', ['tipo' => 'compra', 'referencia_tipo' => 'compra', 'producto_id' => $this->harina->id]);
    }

    public function test_docena_se_convierte_a_unidades_enteras(): void
    {
        $this->actuarComo('encargado_inventario');

        $this->comprar(['items' => [['producto_id' => $this->huevo->id, 'unidad_medida_id' => $this->docena, 'cantidad' => 2, 'costo_unitario' => 1.80]]])
            ->assertCreated();
        $this->assertEquals(24, $this->stock($this->huevo));
        $this->assertEquals(0.15, (float) $this->huevo->fresh()->costo_unitario);

        $this->comprar(['numero_documento' => '00124', 'items' => [['producto_id' => $this->huevo->id, 'unidad_medida_id' => $this->quintal, 'cantidad' => 1, 'costo_unitario' => 1]]])
            ->assertStatus(422)->assertJsonPath('message', 'No se puede convertir de Quintal (masa) a Unidad (unidad)');
    }

    public function test_no_se_registra_dos_veces_el_mismo_documento(): void
    {
        $this->actuarComo('encargado_inventario');
        $uuid = (string) Str::uuid();

        $this->comprar(['uuid' => $uuid])->assertCreated();
        $this->comprar(['uuid' => $uuid])->assertOk(); // reintento: misma compra
        $this->comprar()->assertStatus(422)->assertJsonPath('message', 'Ese documento ya está registrado en la compra C-000001');

        $this->assertSame(1, Compra::count());
    }

    public function test_compra_de_contado_desde_caja_genera_egreso(): void
    {
        $this->actuarComo('cajero')->givePermissionTo('compras.gestionar');

        $this->comprar([
            'condicion' => 'contado',
            'pago'      => ['origen' => 'caja', 'caja_id' => $this->caja->id, 'metodo_pago_id' => $this->metodo('Efectivo')],
        ])->assertCreated()->assertJsonPath('compra.saldo', 0)->assertJsonPath('compra.pagos.0.origen', 'caja');

        $resumen = CajaService::resumen($this->turno);
        $this->assertEquals(50, $resumen['efectivo_esperado']);
        $this->assertEquals(50, $resumen['por_tipo']['pago_proveedor']);
        $this->assertEquals(0, $this->saldoProveedor());
        $this->assertSame('pagada', CuentaPagar::first()->estado);
    }

    public function test_contado_desde_caja_no_puede_superar_el_efectivo(): void
    {
        $this->actuarComo('admin');

        $this->comprar([
            'condicion' => 'contado',
            'items'     => [['producto_id' => $this->harina->id, 'unidad_medida_id' => $this->quintal, 'cantidad' => 3, 'costo_unitario' => 50]],
            'pago'      => ['origen' => 'caja', 'caja_id' => $this->caja->id],
        ])->assertStatus(422)->assertJsonPath('message', 'No hay suficiente efectivo en caja: disponible $100.00');

        // Todo se revierte: ni compra ni inventario
        $this->assertSame(0, Compra::count());
        $this->assertEquals(100, $this->stock($this->harina));
    }

    public function test_anular_compra_saca_el_inventario_y_su_pago_de_contado(): void
    {
        $this->actuarComo('admin');
        $id = $this->comprar([
            'condicion' => 'contado',
            'pago'      => ['origen' => 'caja', 'caja_id' => $this->caja->id],
        ])->json('compra.id');

        $this->postJson("/api/compras/{$id}/anular", ['motivo' => 'Se registró en la caja equivocada'])->assertOk()
            ->assertJsonPath('compra.estado', 'anulada');

        $this->assertEquals(100, $this->stock($this->harina));
        $this->assertEquals(100, CajaService::resumen($this->turno)['efectivo_esperado']);
        $this->assertTrue(PagoProveedor::first()->anulado);
        $this->assertSame('anulada', CuentaPagar::first()->estado);
        $this->assertEquals(0, $this->saldoProveedor());
    }

    public function test_compra_al_credito_con_pagos_no_se_anula(): void
    {
        $this->actuarComo('admin');
        $id = $this->comprar()->json('compra.id');
        $this->postJson("/api/cuentas-pagar/proveedores/{$this->proveedor->id}/pagos", [
            'uuid' => (string) Str::uuid(), 'monto' => 10, 'origen' => 'externo', 'metodo_pago_id' => $this->metodo('Transferencia'), 'referencia' => 'TR-1',
        ])->assertCreated();

        $this->postJson("/api/compras/{$id}/anular", ['motivo' => 'Error'])->assertStatus(422)
            ->assertJsonPath('message', 'La compra tiene pagos registrados (PP-000001): anula primero los pagos');
    }

    // ── Órdenes de compra ─────────────────────────────────────

    public function test_orden_recibida_en_partes(): void
    {
        $this->actuarComo('encargado_inventario');

        $orden = $this->postJson('/api/compras/ordenes', [
            'proveedor_id' => $this->proveedor->id,
            'items'        => [['producto_id' => $this->harina->id, 'unidad_medida_id' => $this->quintal, 'cantidad' => 4, 'costo_unitario' => 50]],
        ])->assertCreated()->assertJsonPath('orden.numero', 'OC-000001')->assertJsonPath('orden.total', 200)->json('orden');

        $this->postJson("/api/compras/ordenes/{$orden['id']}/enviar")->assertOk()->assertJsonPath('orden.estado', 'enviada');

        $linea = $orden['items'][0]['id'];
        $recibir = fn (float $cantidad, string $doc) => $this->comprar([
            'orden_compra_id'  => $orden['id'],
            'numero_documento' => $doc,
            'items'            => [['producto_id' => $this->harina->id, 'unidad_medida_id' => $this->quintal, 'cantidad' => $cantidad, 'costo_unitario' => 50, 'orden_detalle_id' => $linea]],
        ])->assertCreated();

        $recibir(1, 'A-1');
        $this->getJson("/api/compras/ordenes/{$orden['id']}")
            ->assertJsonPath('orden.estado', 'parcial')
            ->assertJsonPath('orden.items.0.pendiente', 3);

        $this->postJson("/api/compras/ordenes/{$orden['id']}/cancelar", ['motivo' => 'Ya no'])->assertStatus(422);

        $recibir(3, 'A-2');
        $this->assertSame('recibida', OrdenCompra::find($orden['id'])->estado);

        // Anular una recepción reabre lo pendiente
        $this->actuarComo('admin');
        $this->postJson('/api/compras/' . Compra::where('numero_documento', 'A-2')->value('id') . '/anular', ['motivo' => 'Error'])->assertOk();
        $this->assertSame('parcial', OrdenCompra::find($orden['id'])->estado);

        $this->postJson("/api/compras/ordenes/{$orden['id']}/cerrar", ['motivo' => 'El proveedor no tiene más'])->assertOk()
            ->assertJsonPath('orden.estado', 'recibida');
    }

    public function test_orden_pdf_y_edicion_solo_en_borrador_o_enviada(): void
    {
        $this->actuarComo('encargado_inventario');
        $orden = $this->postJson('/api/compras/ordenes', [
            'proveedor_id' => $this->proveedor->id,
            'items'        => [['producto_id' => $this->huevo->id, 'unidad_medida_id' => $this->docena, 'cantidad' => 10, 'costo_unitario' => 1.8]],
        ])->json('orden');

        $pdf = $this->get("/api/compras/ordenes/{$orden['id']}/pdf");
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('content-type'));

        $this->postJson("/api/compras/ordenes/{$orden['id']}/cancelar", ['motivo' => 'Duplicada'])->assertOk();
        $this->putJson("/api/compras/ordenes/{$orden['id']}", [
            'proveedor_id' => $this->proveedor->id,
            'items'        => [['producto_id' => $this->huevo->id, 'cantidad' => 1, 'costo_unitario' => 1]],
        ])->assertStatus(422)->assertJsonPath('message', 'Solo se editan órdenes en borrador o enviadas sin recepciones');
    }

    // ── Cuentas por pagar ─────────────────────────────────────

    public function test_pago_externo_se_aplica_a_la_cuenta_mas_antigua(): void
    {
        $this->actuarComo('admin');
        $this->comprar(['numero_documento' => 'F-1']);   // 50
        $this->comprar(['numero_documento' => 'F-2']);   // 50
        [$primera, $segunda] = CuentaPagar::orderBy('id')->get()->all();
        $segunda->update(['fecha_vencimiento' => today()->subDay()]);

        $this->postJson("/api/cuentas-pagar/proveedores/{$this->proveedor->id}/pagos", [
            'uuid' => (string) Str::uuid(), 'monto' => 70, 'origen' => 'externo',
            'metodo_pago_id' => $this->metodo('Transferencia'), 'referencia' => 'TR-99',
        ])->assertCreated()->assertJsonPath('proveedor.saldo', 30);

        $this->assertSame('pagada', $segunda->fresh()->estado);   // vencía antes
        $this->assertEquals(30, (float) $primera->fresh()->saldo);
        $this->assertEquals(100, CajaService::resumen($this->turno)['efectivo_esperado']); // externo: no toca la caja
    }

    public function test_pago_aplicado_a_una_compra_y_anulacion(): void
    {
        $this->actuarComo('admin');
        $this->comprar(['numero_documento' => 'F-1']);
        $this->comprar(['numero_documento' => 'F-2']);
        $segunda = CuentaPagar::orderByDesc('id')->first();

        $pago = $this->postJson("/api/cuentas-pagar/proveedores/{$this->proveedor->id}/pagos", [
            'uuid' => (string) Str::uuid(), 'monto' => 20, 'origen' => 'caja', 'caja_id' => $this->caja->id,
            'aplicaciones' => [['cuenta_pagar_id' => $segunda->id, 'monto' => 20]],
        ])->assertCreated()->json('pago');

        $this->assertEquals(30, (float) $segunda->fresh()->saldo);
        $this->assertEquals(80, CajaService::resumen($this->turno)['efectivo_esperado']);

        $this->postJson("/api/cuentas-pagar/pagos/{$pago['id']}/anular", ['motivo' => 'Monto equivocado'])->assertOk()
            ->assertJsonPath('proveedor.saldo', 100);
        $this->assertEquals(50, (float) $segunda->fresh()->saldo);
        $this->assertEquals(100, CajaService::resumen($this->turno)['efectivo_esperado']);

        $this->postJson("/api/cuentas-pagar/proveedores/{$this->proveedor->id}/pagos", [
            'uuid' => (string) Str::uuid(), 'monto' => 150, 'origen' => 'externo',
        ])->assertStatus(422)->assertJsonPath('message', 'A Molinos S.A. se le deben $100.00: el pago no puede ser mayor');
    }

    public function test_nota_de_credito_rebaja_la_deuda_y_se_puede_anular(): void
    {
        $this->actuarComo('admin');
        $this->comprar(['numero_documento' => 'F-9']); // $50 al crédito
        $cuenta = CuentaPagar::first();

        $this->postJson("/api/cuentas-pagar/{$cuenta->id}/notas-credito", ['monto' => 60, 'motivo' => 'Digitado de más'])
            ->assertStatus(422)->assertJsonPath('message', 'La nota no puede ser mayor a lo que se debe de esta compra ($50.00)');
        $this->postJson("/api/cuentas-pagar/{$cuenta->id}/notas-credito", ['monto' => 12.5])->assertStatus(422);

        $nota = $this->postJson("/api/cuentas-pagar/{$cuenta->id}/notas-credito", ['monto' => 12.5, 'motivo' => 'Se digitaron 5 lb de más', 'documento' => 'NC-77'])
            ->assertOk()->assertJsonPath('cuenta.saldo', 37.5)->assertJsonPath('cuenta.notas_credito.0.numero', 'NC-000001')
            ->json('cuenta.notas_credito.0');
        $this->assertEquals(37.5, $this->saldoProveedor());
        $this->assertDatabaseHas('auditorias', ['accion' => 'nota_credito_proveedor']);

        // Rebajar todo deja la cuenta pagada
        $this->postJson("/api/cuentas-pagar/{$cuenta->id}/notas-credito", ['monto' => 37.5, 'motivo' => 'Devolución completa'])->assertOk()
            ->assertJsonPath('cuenta.estado', 'pagada');
        $this->assertEquals(0, $this->saldoProveedor());
        $this->postJson("/api/cuentas-pagar/{$cuenta->id}/notas-credito", ['monto' => 1, 'motivo' => 'x'])
            ->assertStatus(422)->assertJsonPath('message', 'Esta cuenta ya está pagada: no hay saldo que rebajar');

        // Anular la primera: esos $12.50 vuelven a deberse
        $this->postJson("/api/cuentas-pagar/notas-credito/{$nota['id']}/anular", ['motivo' => 'Era correcto'])->assertOk()
            ->assertJsonPath('cuenta.saldo', 12.5)->assertJsonPath('cuenta.estado', 'pendiente');
        $this->assertEquals(12.5, $this->saldoProveedor());
        $this->postJson("/api/cuentas-pagar/notas-credito/{$nota['id']}/anular", ['motivo' => 'otra vez'])->assertStatus(422);

        // Sin el permiso de cuentas por pagar no se puede
        $this->actuarComo('encargado_inventario');
        $this->postJson("/api/cuentas-pagar/{$cuenta->id}/notas-credito", ['monto' => 1, 'motivo' => 'x'])->assertStatus(403);
    }

    public function test_cartera_por_pagar_e_historial_de_costos(): void
    {
        $this->actuarComo('admin');
        $this->comprar(['numero_documento' => 'F-1']);
        $this->comprar(['numero_documento' => 'F-2', 'items' => [['producto_id' => $this->harina->id, 'unidad_medida_id' => $this->quintal, 'cantidad' => 1, 'costo_unitario' => 55]]]);
        CuentaPagar::orderBy('id')->first()->update(['fecha_vencimiento' => today()->subDays(3)]);

        $this->getJson('/api/cuentas-pagar?estado=vencidas')->assertOk()
            ->assertJsonCount(1, 'cuentas')
            ->assertJsonPath('cuentas.0.dias_vencida', 3)
            ->assertJsonPath('resumen.total', 105)
            ->assertJsonPath('resumen.vencido', 50);

        $this->getJson("/api/compras/historial-producto/{$this->harina->id}")->assertOk()
            ->assertJsonCount(2, 'compras')
            ->assertJsonPath('compras.0.costo_base', 0.55);

        $this->getJson("/api/compras/proveedores/{$this->proveedor->id}")->assertOk()
            ->assertJsonPath('resumen.compras', 2)
            ->assertJsonPath('resumen.vencido', 50)
            ->assertJsonPath('productos.0.nombre', 'Harina');
    }

    public function test_permisos_de_compras(): void
    {
        $this->actuarComo('cajero');
        $this->getJson('/api/compras')->assertStatus(403);
        $this->postJson('/api/compras/proveedores', ['nombre' => 'X'])->assertStatus(403);

        $this->actuarComo('encargado_inventario');
        $this->getJson('/api/compras')->assertOk();
        $this->getJson('/api/cuentas-pagar')->assertStatus(403); // pagar a proveedores es aparte
    }
}
