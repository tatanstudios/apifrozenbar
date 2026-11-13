<?php

namespace Tests\Feature;

use App\Models\AreaPreparacion;
use App\Models\Caja;
use App\Models\Categoria;
use App\Models\CategoriaGasto;
use App\Models\Comanda;
use App\Models\ConfiguracionNegocio;
use App\Models\Inventario;
use App\Models\MetodoPago;
use App\Models\Modificador;
use App\Models\MovimientoInventario;
use App\Models\Orden;
use App\Models\OrdenItem;
use App\Models\Producto;
use App\Models\Terminal;
use App\Models\TurnoCaja;
use App\Models\UnidadesMedida;
use App\Models\Venta;
use Database\Seeders\CajaSeeder;
use Database\Seeders\UnidadesMedidaSeeder;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Simulación de un día completo de el negocio, de la apertura al cierre, por la API
 * (como lo hace la tablet). Al final se cruzan tres fuentes que deben decir lo mismo:
 * el cierre de caja, los reportes y el inventario (existencias y kárdex).
 *
 * Guion (precios sin IVA):
 *  1. Ana (mesera) abre turno con $20.00.
 *  2. Venta rápida: 10 panes + 2 limonadas = $3.50, paga con $5.00 (cambio $1.50).
 *  3. Venta rápida: 1 pastel $12.00 con tarjeta (se anula más tarde con PIN).
 *  4. Mostrador: pedido de «Juan», café con leche + sándwich = $5.00, efectivo.
 *  5. Mesa 1: 3 sándwiches, 2 limonadas, 1 café con leche; se envía; se cancela 1 sándwich con PIN;
 *     el café pasa a otra cuenta. Cuenta 1 ($9.00): $5.00 efectivo + $4.00 tarjeta.
 *     Cuenta 2 ($1.50) con descuento de $0.50 autorizado: $1.00 efectivo.
 *  6. Mesa 2: 1 sándwich, se envía, se anula la comanda con PIN y se libera la mesa.
 *  7. Se anula la venta del pastel (PIN).
 *  8. Devolución de 2 panes de la primera venta ($0.30 en efectivo, regresan al inventario).
 *  9. Cena del personal (consumo interno, $0.00): 2 huevos.
 * 10. Gasto de gas desde la caja: $2.00.
 * 11. Cierre: se cuentan $32.00 (esperado $32.20 → faltante $0.20).
 */
class DiaCompletoTest extends TestCase
{
    private Caja $caja;
    private array $u;
    private array $p = [];
    private int $efectivo;
    private int $tarjeta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CajaSeeder::class, UnidadesMedidaSeeder::class]);
        ConfiguracionNegocio::actual()->update(['iva_porcentaje' => 0, 'precios_incluyen_iva' => true]);

        $this->caja = Caja::first();
        $this->u = UnidadesMedida::pluck('id', 'nombre')->all();
        $this->efectivo = MetodoPago::where('nombre', 'Efectivo')->value('id');
        $this->tarjeta = MetodoPago::where('nombre', 'Tarjeta')->value('id');

        $barra = Categoria::create(['nombre' => 'Bebidas', 'estado' => true, 'area_preparacion_id' => AreaPreparacion::where('nombre', 'Barra')->value('id')]);
        $cocina = Categoria::create(['nombre' => 'Comida', 'estado' => true, 'area_preparacion_id' => AreaPreparacion::where('nombre', 'Cocina')->value('id')]);
        $panes = Categoria::create(['nombre' => 'Pan', 'estado' => true]);

        // Con existencia propia
        $this->p['pan'] = $this->conStock('Pan francés', 'producto_terminado', 0.15, 0.05, 200, 0, $panes);
        $this->p['pastel'] = $this->conStock('Pastel', 'producto_terminado', 12.00, 5.00, 4, 2, $panes);
        // Insumos
        $this->p['limon'] = $this->conStock('Limón', 'materia_prima', 0, 0.05, 30, 20);
        $this->p['azucar'] = $this->conStock('Azúcar', 'materia_prima', 0, 0.002, 5000, 1000, null, 'Gramo');
        $this->p['leche'] = $this->conStock('Leche', 'materia_prima', 0, 0.001, 2000, 500, null, 'Mililitro');
        $this->p['huevo'] = $this->conStock('Huevo', 'materia_prima', 0, 0.15, 30, 0);
        // Sin existencia: salen de su receta
        $this->p['limonada'] = $this->plato('Limonada', 1.00, $barra);
        $this->p['cafe'] = $this->plato('Café', 1.25, $barra);
        $this->p['sandwich'] = $this->plato('Sándwich', 3.50, $cocina);
        $this->p['cena'] = $this->plato('Cena del personal', 0, $cocina, ['consumo_interno' => true]);
    }

    private function conStock(string $nombre, string $clase, float $precio, float $costo, float $stock, float $minimo, ?Categoria $cat = null, string $unidad = 'Unidad'): Producto
    {
        $p = Producto::create([
            'nombre' => $nombre, 'tipo' => 'bien', 'clase' => $clase, 'se_vende' => $precio > 0,
            'controla_stock' => true, 'permite_fraccion' => $unidad !== 'Unidad',
            'precio_venta' => $precio, 'costo_unitario' => $costo, 'unidad_medida_id' => $this->u[$unidad],
        ]);
        Inventario::create(['producto_id' => $p->id, 'stock_actual' => $stock, 'stock_minimo' => $minimo]);
        if ($cat) { $p->categorias()->attach($cat->id); }
        return $p;
    }

    private function plato(string $nombre, float $precio, Categoria $cat, array $extra = []): Producto
    {
        $p = Producto::create(array_merge([
            'nombre' => $nombre, 'tipo' => 'servicio', 'clase' => 'producto_terminado', 'se_vende' => true,
            'controla_stock' => false, 'precio_venta' => $precio, 'unidad_medida_id' => $this->u['Unidad'],
        ], $extra));
        $p->categorias()->attach($cat->id);
        return $p;
    }

    private function receta(string $producto, array $ingredientes): void
    {
        $this->postJson('/api/produccion/recetas', [
            'producto_id' => $this->p[$producto]->id, 'rendimiento' => 1,
            'ingredientes' => array_map(fn ($i) => [
                'producto_id' => $this->p[$i[0]]->id, 'unidad_medida_id' => $this->u[$i[1]], 'cantidad' => $i[2],
            ], $ingredientes),
        ])->assertCreated();
    }

    private function stock(string $p): float
    {
        return round((float) Inventario::where('producto_id', $this->p[$p]->id)->value('stock_actual'), 4);
    }

    private function uuid(): string
    {
        return (string) Str::uuid();
    }

    private function item(string $p, float $cantidad, array $extra = []): array
    {
        return ['producto_id' => $this->p[$p]->id, 'cantidad' => $cantidad, ...$extra];
    }

    public function test_un_dia_completo_cuadra_en_caja_reportes_e_inventario(): void
    {
        // ── Preparación (administrador): recetas y extra con insumo ──
        $admin = $this->actuarComo('admin', ['nombre' => 'Gerente', 'pin' => '4321', 'pin_actualizado_at' => now()]);
        $this->receta('limonada', [['limon', 'Unidad', 4], ['azucar', 'Gramo', 30]]);   // costo 0.26
        $this->receta('cafe', [['azucar', 'Gramo', 10]]);                                // costo 0.02
        $this->receta('sandwich', [['huevo', 'Unidad', 2]]);                             // costo 0.30
        $this->receta('cena', [['huevo', 'Unidad', 2]]);                                 // costo 0.30
        $this->postJson('/api/mesas/config/modificadores', ['nombre' => 'Leche', 'productos' => [$this->p['cafe']->id], 'opciones' => [
            ['nombre' => 'Con leche', 'precio' => 0.25, 'producto_id' => $this->p['leche']->id, 'cantidad' => 150, 'unidad_medida_id' => $this->u['Mililitro']],
            ['nombre' => 'Negro'],
        ]])->assertCreated();
        $conLeche = Modificador::where('nombre', 'Con leche')->value('id');
        $cafeConLeche = fn (float $c = 1) => $this->item('cafe', $c, ['modificadores' => [$conLeche]]);

        // ── 1. Ana abre su turno ──
        $ana = $this->crearUsuario('mesero', ['nombre' => 'Ana']);
        Sanctum::actingAs($ana, ['*'], 'sanctum');
        $turnoId = $this->postJson('/api/caja/turnos/abrir', ['caja_id' => $this->caja->id, 'fondo_inicial' => 20])
            ->assertSuccessful()->json('turno.id');
        $this->assertNotNull($turnoId);

        // ── 2. Venta rápida en efectivo con cambio ──
        $v1 = $this->postJson('/api/pos/ventas', [
            'uuid' => $this->uuid(), 'caja_id' => $this->caja->id,
            'items' => [$this->item('pan', 10), $this->item('limonada', 2)],
            'pagos' => [['metodo_pago_id' => $this->efectivo, 'monto' => 5.00]],
        ])->assertCreated()->json('venta');
        $this->assertEquals(3.50, $v1['total']);
        $this->assertEquals(1.50, $v1['cambio'] ?? null, 'El ticket debe indicar el cambio');

        // ── 3. Pastel con tarjeta ──
        $v2 = $this->postJson('/api/pos/ventas', [
            'uuid' => $this->uuid(), 'caja_id' => $this->caja->id,
            'items' => [$this->item('pastel', 1)],
            'pagos' => [['metodo_pago_id' => $this->tarjeta, 'monto' => 12.00]],
        ])->assertCreated()->json('venta');

        // ── 4. Pedido de mostrador ──
        $juan = $this->postJson('/api/mostrador/pedidos', [
            'uuid' => $this->uuid(), 'caja_id' => $this->caja->id, 'nombre' => 'Juan',
            'items' => [$cafeConLeche(), $this->item('sandwich', 1)],
        ])->assertCreated()->json('pedido');
        $this->assertEquals(5.00, $juan['por_cobrar']);
        $this->postJson("/api/mesas/ordenes/{$juan['orden_id']}/cobrar", [
            'uuid' => $this->uuid(), 'caja_id' => $this->caja->id, 'ticket_id' => $juan['ticket_id'],
            'pagos' => [['metodo_pago_id' => $this->efectivo, 'monto' => 5.00]],
        ])->assertCreated();

        // ── 5. Mesa 1 ──
        $mesa1 = Terminal::where('nombre', 'MESA 1')->first();
        $orden = $this->postJson("/api/mesas/terminales/{$mesa1->id}/abrir", [])->assertOk()->json('orden');
        $t1 = $orden['tickets'][0]['id'];
        $this->postJson("/api/mesas/ordenes/{$orden['id']}/items", ['ticket_id' => $t1, 'items' => [
            $this->item('sandwich', 3), $this->item('limonada', 2), $cafeConLeche(),
        ]])->assertOk();
        $this->postJson("/api/mesas/ordenes/{$orden['id']}/enviar")->assertOk();
        $this->assertSame(2, Comanda::where('orden_id', $orden['id'])->count(), 'Una comanda para cocina y otra para barra');

        $sandwich = OrdenItem::where('orden_id', $orden['id'])->where('producto_id', $this->p['sandwich']->id)->value('id');
        $this->postJson("/api/mesas/items/{$sandwich}/quitar", ['motivo' => 'Se fue uno', 'cantidad' => 1])->assertStatus(403);
        $this->postJson("/api/mesas/items/{$sandwich}/quitar", ['motivo' => 'Se fue uno', 'cantidad' => 1, 'autorizacion' => '4321'])->assertOk();

        $t2 = $this->postJson("/api/mesas/ordenes/{$orden['id']}/tickets", ['nombre' => 'Señora'])->assertSuccessful()->json('ticket.id')
            ?? collect($this->getJson("/api/mesas/terminales/{$mesa1->id}")->json('orden.tickets'))->last()['id'];
        $cafe = OrdenItem::where('orden_id', $orden['id'])->where('producto_id', $this->p['cafe']->id)->value('id');
        $this->postJson("/api/mesas/ordenes/{$orden['id']}/mover", ['item_ids' => [$cafe], 'ticket_id' => $t2])->assertOk();

        $this->postJson("/api/mesas/ordenes/{$orden['id']}/cobrar", [
            'uuid' => $this->uuid(), 'caja_id' => $this->caja->id, 'ticket_id' => $t1,
            'pagos' => [['metodo_pago_id' => $this->efectivo, 'monto' => 5.00], ['metodo_pago_id' => $this->tarjeta, 'monto' => 4.00]],
        ])->assertCreated()->assertJsonPath('venta.total', 9);

        $cobroT2 = [
            'uuid' => $this->uuid(), 'caja_id' => $this->caja->id, 'ticket_id' => $t2,
            'descuento_global' => ['tipo' => 'monto', 'valor' => 0.50],
            'pagos' => [['metodo_pago_id' => $this->efectivo, 'monto' => 1.00]],
        ];
        $this->postJson("/api/mesas/ordenes/{$orden['id']}/cobrar", $cobroT2)->assertStatus(403); // 33 % > 10 % sin autorización
        $this->postJson("/api/mesas/ordenes/{$orden['id']}/cobrar", [...$cobroT2, 'autorizacion' => '4321'])
            ->assertCreated()->assertJsonPath('venta.total', 1);
        $this->assertSame('cerrada', Orden::find($orden['id'])->estado, 'La mesa 1 queda libre al cobrar todo');

        // ── 6. Mesa 2: comanda anulada y mesa liberada ──
        $mesa2 = Terminal::where('nombre', 'MESA 2')->first();
        $orden2 = $this->postJson("/api/mesas/terminales/{$mesa2->id}/abrir", [])->assertOk()->json('orden');
        $this->postJson("/api/mesas/ordenes/{$orden2['id']}/items", ['items' => [$this->item('sandwich', 1)]])->assertOk();
        $this->postJson("/api/mesas/ordenes/{$orden2['id']}/enviar")->assertOk();
        $comanda = Comanda::where('orden_id', $orden2['id'])->value('id');
        $this->postJson("/api/mesas/comandas/{$comanda}/anular", ['motivo' => 'Mesa equivocada', 'autorizacion' => '4321'])->assertOk();
        $this->postJson("/api/mesas/ordenes/{$orden2['id']}/cerrar", [])->assertOk();
        $this->assertSame('cancelada', Orden::find($orden2['id'])->estado, 'Sin nada cobrado, la orden queda cancelada y la mesa libre');

        // ── 7. Anular el pastel ──
        $this->postJson("/api/pos/ventas/{$v2['id']}/anular", ['motivo' => 'Cobro equivocado'])->assertStatus(403);
        $this->postJson("/api/pos/ventas/{$v2['id']}/anular", ['motivo' => 'Cobro equivocado', 'autorizacion' => '4321'])->assertOk();

        // ── 8. Devolución de 2 panes ──
        $detallePan = collect($v1['detalles'])->firstWhere('producto_id', $this->p['pan']->id)['id'];
        $this->postJson("/api/pos/ventas/{$v1['id']}/devoluciones", [
            'uuid' => $this->uuid(), 'caja_id' => $this->caja->id, 'motivo' => 'Pan duro', 'autorizacion' => '4321',
            'items' => [['venta_detalle_id' => $detallePan, 'cantidad' => 2, 'reingresa_inventario' => true]],
        ])->assertCreated();

        // ── 9. Cena del personal ──
        $this->postJson('/api/pos/ventas', [
            'uuid' => $this->uuid(), 'caja_id' => $this->caja->id, 'items' => [$this->item('cena', 1)], 'pagos' => [],
        ])->assertCreated()->assertJsonPath('venta.total', 0);

        // ── 10. Gasto desde la caja ──
        $this->post('/api/gastos', [
            'uuid' => $this->uuid(), 'categoria_id' => CategoriaGasto::where('nombre', 'Gas')->value('id'),
            'descripcion' => 'Tambo de gas', 'monto' => 2, 'origen' => 'caja', 'caja_id' => $this->caja->id,
        ], ['Accept' => 'application/json'])->assertCreated();

        // ── Antes de cerrar: lo que el turno espera en la gaveta ──
        // 20 + 3.50 + 5.00 + 5.00 + 1.00 − 0.30 − 2.00
        $actual = $this->getJson("/api/caja/turno-actual?caja_id={$this->caja->id}")->assertOk()->json();
        $resumen = $actual['resumen'] ?? $actual['turno']['resumen'] ?? null;
        $this->assertNotNull($resumen, 'turno-actual debe traer el resumen');
        $this->assertEquals(32.20, $resumen['efectivo_esperado']);
        $this->assertEquals(18.50, $resumen['totales']['ventas']);
        $this->assertEquals(0.30, $resumen['totales']['devoluciones']);
        $this->assertEquals(2.00, $resumen['totales']['egresos']);
        $tarjeta = collect($resumen['por_metodo'])->firstWhere('nombre', 'Tarjeta');
        $this->assertEquals(4.00, $tarjeta['neto'], 'La tarjeta del pastel anulado no cuenta');

        // ── 11. Cierre con faltante ──
        $cierre = $this->postJson("/api/caja/turnos/{$turnoId}/cerrar", ['efectivo_contado' => 32.00])->assertOk()->json();
        $turno = TurnoCaja::find($turnoId);
        $this->assertSame('cerrado', $turno->estado);
        $this->assertEquals(32.20, (float) $turno->efectivo_esperado);
        $this->assertEquals(-0.20, (float) $turno->diferencia);
        $this->assertSame($ana->id, $turno->usuario_cierre_id);

        // Lista de compras: solo el limón quedó bajo su mínimo (14 < 20)
        $porComprar = collect($turno->resumen['por_comprar'])->pluck('producto')->all();
        $this->assertSame(['Limón'], $porComprar, 'Lista de compras del cierre: ' . json_encode($turno->resumen['por_comprar']));

        // Consumo del turno: lo vendido y lo del personal, aparte
        $consumo = collect($turno->resumen['consumo'])->keyBy('producto');
        $this->assertEquals(16, (float) $consumo['Limón']['vendido']);
        $this->assertEquals(2, (float) $consumo['Huevo']['consumo_interno']);
        $this->assertEquals(6, (float) $consumo['Huevo']['vendido']);

        // ── Inventario ──
        $this->assertEquals(192, $this->stock('pan'), 'Pan: 200 − 10 + 2 devueltos');
        $this->assertEquals(4, $this->stock('pastel'), 'Pastel: vendido y anulado');
        $this->assertEquals(14, $this->stock('limon'), 'Limón: 4 limonadas × 4');
        $this->assertEquals(4860, $this->stock('azucar'), 'Azúcar: 4 × 30 g + 2 × 10 g');
        $this->assertEquals(1700, $this->stock('leche'), 'Leche: 2 cafés con leche × 150 ml');
        $this->assertEquals(22, $this->stock('huevo'), 'Huevo: 3 sándwiches cobrados + cena; no el cancelado ni la comanda anulada');

        // El kárdex de cada producto explica su existencia
        $inicial = ['pan' => 200, 'pastel' => 4, 'limon' => 30, 'azucar' => 5000, 'leche' => 2000, 'huevo' => 30];
        foreach ($inicial as $clave => $stockInicial) {
            $movs = MovimientoInventario::where('producto_id', $this->p[$clave]->id)->orderBy('id')->get();
            $ultimo = $movs->last();
            $this->assertNotNull($ultimo, "{$clave} debe tener movimientos");
            $this->assertEquals($this->stock($clave), round((float) $ultimo->stock_resultante, 4), "Kárdex de {$clave}: el último saldo es la existencia");
        }

        // ── Reportes del día ──
        Sanctum::actingAs($admin, ['*'], 'sanctum');
        $diario = $this->getJson('/api/reportes/ventas_diarias')->assertOk()->json('reporte.totales');
        // Venta neta 18.50 − 0.30; costo 1.02 + 0.47 + 1.12 + 0.17 − 0.10 devuelto
        $this->assertEquals(18.20, $diario['neto'], 'Ventas por día: ' . json_encode($diario));
        $this->assertEquals(0.30, $diario['devoluciones']);
        $this->assertEquals(2.68, $diario['costo'], 'El costo no incluye el consumo interno ni lo anulado');
        $this->assertEquals(15.52, $diario['utilidad']);
        $this->assertEquals(0.30, $diario['consumo_interno'], 'La cena del personal va aparte, al costo');
        $this->assertEquals(4, $diario['tickets'], 'La cena del personal no es un ticket');

        $metodos = collect($this->getJson('/api/reportes/ventas_metodo')->assertOk()->json('reporte.filas'))->keyBy('metodo');
        $this->assertEquals(14.20, $metodos['Efectivo']['neto'], 'Efectivo: ' . json_encode($metodos['Efectivo']));
        $this->assertEquals(4.00, $metodos['Tarjeta']['neto']);
        // El reporte de formas de pago cuadra con el cierre
        $efectivoCaja = collect($turno->resumen['por_metodo'])->firstWhere('nombre', 'Efectivo')['neto'];
        $this->assertEquals($efectivoCaja + 2.00, $metodos['Efectivo']['neto'], 'Efectivo de ventas = neto de la gaveta + el gasto');

        $cierres = $this->getJson('/api/reportes/cierres_caja')->assertOk()->json('reporte.filas');
        $this->assertCount(1, $cierres);
        $this->assertEquals(18.50, $cierres[0]['ventas']);
        $this->assertEquals(32.20, $cierres[0]['esperado']);
        $this->assertEquals(-0.20, $cierres[0]['diferencia']);

        $resultados = $this->getJson('/api/reportes/resultados')->assertOk()->json('reporte.totales');
        $this->assertEquals(2.00, $resultados['gastos'], 'Utilidad estimada: ' . json_encode($resultados));
        $this->assertEquals(0.30, $resultados['consumo_interno']);
        $this->assertEquals(13.22, $resultados['resultado'], '15.52 − 0.30 de consumo − 2.00 de gasto');

        $vendidos = collect($this->getJson('/api/reportes/productos_vendidos')->assertOk()->json('reporte.filas'))->keyBy('producto');
        $this->assertEquals(8, $vendidos['Pan francés']['cantidad'], 'Pan neto de devoluciones');
        $this->assertArrayNotHasKey('Pastel', $vendidos->all(), 'Lo anulado no aparece');
        $this->assertArrayNotHasKey('Cena del personal', $vendidos->all(), 'El consumo interno no es una venta');
        $this->assertEquals(3, $vendidos['Sándwich']['cantidad'] ?? null, 'Sándwiches: ' . json_encode($vendidos['Sándwich'] ?? null) . ' (1 de Juan + 2 de la mesa = 3)');

        // ── Auditoría: cada autorización con PIN quedó registrada ──
        foreach (['cancelar_item', 'anular_venta', 'devolucion', 'descuento_autorizado'] as $accion) {
            $this->assertDatabaseHas('auditorias', ['accion' => $accion]);
        }
        $this->assertSame(5, Venta::where('turno_id', $turnoId)->where('estado', '!=', 'anulada')->count());
        $this->assertNotEmpty($cierre);
    }
}
