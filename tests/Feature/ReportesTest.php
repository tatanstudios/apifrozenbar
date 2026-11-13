<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\CategoriaGasto;
use App\Models\ConfiguracionNegocio;
use App\Models\Inventario;
use App\Models\MetodoPago;
use App\Models\Producto;
use App\Models\UnidadesMedida;
use App\Models\Venta;
use App\Services\CajaService;
use App\Services\ReportesService;
use Database\Seeders\CajaSeeder;
use Database\Seeders\UnidadesMedidaSeeder;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReportesTest extends TestCase
{
    private Caja $caja;
    private Producto $latteHelado;
    private Producto $frappe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CajaSeeder::class, UnidadesMedidaSeeder::class]);
        // Precios con IVA del 13%
        ConfiguracionNegocio::actual()->update(['iva_porcentaje' => 13, 'precios_incluyen_iva' => true]);

        $this->caja = Caja::first();
        $unidad = UnidadesMedida::where('nombre', 'Unidad')->value('id');
        $this->latteHelado = Producto::create(['nombre' => 'Latte helado', 'tipo' => 'bien', 'clase' => 'producto_terminado',
            'precio_venta' => 1.13, 'costo_unitario' => 0.40, 'unidad_medida_id' => $unidad]);
        $this->frappe = Producto::create(['nombre' => 'Frappé', 'tipo' => 'bien', 'clase' => 'producto_terminado',
            'precio_venta' => 11.30, 'costo_unitario' => 4, 'unidad_medida_id' => $unidad]);
        Inventario::create(['producto_id' => $this->latteHelado->id, 'stock_actual' => 100]);
        Inventario::create(['producto_id' => $this->frappe->id, 'stock_actual' => 10]);

        CajaService::abrir($this->caja->id, 100, $this->crearUsuario('cajero')->id);
    }

    private function vender(array $items, string $metodo = 'Efectivo', float $monto = 100)
    {
        return $this->postJson('/api/pos/ventas', [
            'uuid' => (string) Str::uuid(), 'caja_id' => $this->caja->id, 'items' => $items,
            'pagos' => [['metodo_pago_id' => MetodoPago::where('nombre', $metodo)->value('id'), 'monto' => $monto, 'referencia' => 'R1']],
        ])->assertCreated();
    }

    // Venta de 10 lattes helados ($11.30) y 1 frappé ($11.30, con tarjeta); se devuelven 2 lattes helados
    private function diaDeVentas(): void
    {
        $this->actuarComo('admin');
        $venta = $this->vender([['producto_id' => $this->latteHelado->id, 'cantidad' => 10]], 'Efectivo', 20)->json('venta');
        $this->vender([['producto_id' => $this->frappe->id, 'cantidad' => 1]], 'Tarjeta', 11.30);

        $this->postJson("/api/pos/ventas/{$venta['id']}/devoluciones", [
            'uuid' => (string) Str::uuid(), 'caja_id' => $this->caja->id, 'motivo' => 'Café frío',
            'items' => [['venta_detalle_id' => $venta['detalles'][0]['id'], 'cantidad' => 2]],
        ])->assertCreated();

        $this->post('/api/gastos', [
            'uuid' => (string) Str::uuid(), 'categoria_id' => CategoriaGasto::where('nombre', 'Gas')->value('id'),
            'descripcion' => 'Gas', 'monto' => 5, 'origen' => 'caja', 'caja_id' => $this->caja->id,
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->postJson('/api/mermas', ['items' => [['uuid' => (string) Str::uuid(), 'producto_id' => $this->latteHelado->id, 'cantidad' => 3, 'motivo' => 'vencido']]])
            ->assertCreated();
    }

    public function test_ventas_diarias_restan_devoluciones_y_calculan_utilidad(): void
    {
        $this->diaDeVentas();

        $r = $this->getJson('/api/reportes/ventas_diarias')->assertOk()->json('reporte');

        // Ventas 22.60, devolución 2.26 → neto 20.34 (sin IVA); costo 4.00 + 4.00 - 0.80 = 7.20
        $this->assertEquals(2, $r['totales']['tickets']);
        $this->assertEquals(22.6, $r['totales']['ventas']);
        $this->assertEquals(2.26, $r['totales']['devoluciones']);
        $this->assertEquals(20.34, $r['totales']['neto']);
        $this->assertEquals(20.34, $r['totales']['base']);
        $this->assertEquals(7.2, $r['totales']['costo']);
        $this->assertEquals(13.14, $r['totales']['utilidad']);
        $this->assertEquals(64.6, $r['totales']['margen']);
    }

    public function test_resultados_restan_mermas_y_gastos(): void
    {
        $this->diaDeVentas();

        // 13.14 de utilidad bruta − 1.20 de merma (3 lattes helados a 0.40) − 5.00 de gas
        $this->getJson('/api/reportes/resultados')->assertOk()
            ->assertJsonPath('reporte.totales.utilidad_bruta', 13.14)
            ->assertJsonPath('reporte.totales.mermas', 1.2)
            ->assertJsonPath('reporte.totales.gastos', 5)
            ->assertJsonPath('reporte.totales.resultado', 6.94);
    }

    public function test_metodos_productos_y_empleados(): void
    {
        $this->diaDeVentas();

        $metodos = collect($this->getJson('/api/reportes/ventas_metodo')->json('reporte.filas'))->keyBy('metodo');
        $this->assertEquals(9.04, $metodos['Efectivo']['neto']);   // 11.30 − 2.26
        $this->assertEquals(11.3, $metodos['Tarjeta']['neto']);

        $productos = $this->getJson('/api/reportes/productos_vendidos')->json('reporte.filas');
        $this->assertSame('Frappé', $productos[0]['producto']);
        $this->assertEquals(8, $productos[1]['cantidad']);            // 10 − 2 devueltos
        $this->assertEquals(9.04, $productos[1]['ventas']);

        $this->getJson('/api/reportes/ventas_empleado')->assertJsonPath('reporte.filas.0.tickets', 2);
    }

    public function test_venta_anulada_no_cuenta(): void
    {
        $this->actuarComo('admin');
        $id = $this->vender([['producto_id' => $this->frappe->id, 'cantidad' => 1]])->json('venta.id');
        $this->postJson("/api/pos/ventas/{$id}/anular", ['motivo' => 'Error'])->assertOk();

        $this->getJson('/api/reportes/ventas_diarias')->assertJsonCount(0, 'reporte.filas');
        $this->getJson('/api/reportes/ventas_empleado')->assertJsonPath('reporte.filas.0.anuladas', 1);
    }

    public function test_todos_los_reportes_responden(): void
    {
        $this->diaDeVentas();

        foreach (array_keys(ReportesService::REPORTES) as $tipo) {
            $params = $tipo === 'kardex' ? "?producto_id={$this->latteHelado->id}" : '';
            $this->getJson("/api/reportes/{$tipo}{$params}")->assertOk()
                ->assertJsonStructure(['reporte' => ['titulo', 'periodo', 'columnas', 'filas']]);
        }

        $this->getJson('/api/reportes/kardex')->assertStatus(422)->assertJsonPath('message', 'Elige el producto del kárdex');
        $this->getJson('/api/reportes/inventado')->assertStatus(422);
        $this->getJson('/api/reportes/ventas_diarias?desde=2025-01-01&hasta=2026-11-01')->assertStatus(422)
            ->assertJsonPath('message', 'El periodo no puede ser mayor a un año');
    }

    public function test_inventario_valorizado(): void
    {
        $this->actuarComo('admin');

        // Latte 100 × 0.40 + frappé 10 × 4 = 80
        $this->getJson('/api/reportes/inventario_valorizado')->assertOk()
            ->assertJsonPath('reporte.totales.valor', 80)
            ->assertJsonPath('reporte.indicadores.0.valor', 80);
    }

    public function test_exportar_excel_y_pdf(): void
    {
        $this->diaDeVentas();

        $excel = $this->get('/api/reportes/ventas_diarias/excel');
        $excel->assertOk();
        $this->assertStringContainsString('spreadsheetml', $excel->headers->get('content-type'));
        $this->assertStringContainsString('ventas-por-dia', $excel->headers->get('content-disposition'));

        $pdf = $this->get('/api/reportes/productos_vendidos/pdf');
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('content-type'));

        $this->assertDatabaseHas('auditorias', ['modulo' => 'reportes', 'accion' => 'exportar_excel']);
    }

    public function test_panel_y_permisos(): void
    {
        $this->diaDeVentas();

        $this->actuarComo('cajero');
        $this->getJson('/api/panel')->assertOk()
            ->assertJsonPath('panel.ventas.tickets', 2)
            ->assertJsonMissingPath('panel.mes');
        $this->getJson('/api/reportes/ventas_diarias')->assertStatus(403);

        // El encargado ve reportes pero no exporta
        $this->actuarComo('encargado_inventario');
        $this->getJson('/api/reportes/inventario_valorizado')->assertOk();
        $this->get('/api/reportes/inventario_valorizado/excel', ['Accept' => 'application/json'])->assertStatus(403);

        $this->actuarComo('admin');
        $this->getJson('/api/panel')->assertJsonPath('panel.mes.gastos', 5)->assertJsonStructure(['panel' => ['ultimos_30', 'cartera']]);
    }

    public function test_panel_resume_el_rango_elegido(): void
    {
        $this->diaDeVentas();
        $hoy = today()->toDateString();

        $r = $this->getJson("/api/panel?desde={$hoy}&hasta={$hoy}")->assertOk()->json('panel.rango');

        // Mismos números que ventas diarias: neto 20.34, utilidad 13.14; gastos 5
        $this->assertEquals(20.34, $r['total_ventas']);
        $this->assertEquals(5, $r['total_gastos']);
        $this->assertEquals(13.14, $r['utilidad_bruta']);
        $this->assertEquals(8.14, $r['utilidad_neta']);
        $this->assertSame(2, $r['total_pedidos']);
        $this->assertSame('Tarjeta', $r['ultimos_pedidos'][0]['pago']);
        $this->assertSame('Consumidor final', $r['ultimos_pedidos'][0]['cliente']);
        $this->assertSame(2, $r['total_productos']);
        $this->assertEquals(8, $r['mas_vendidos'][0]['cantidad']); // 10 lattes helados menos 2 devueltos

        // Un periodo sin ventas viene en cero
        $this->getJson('/api/panel?desde=2020-01-01&hasta=2020-01-31')->assertJsonPath('panel.rango.total_pedidos', 0);
        $this->getJson("/api/panel?desde={$hoy}&hasta=2020-01-01")->assertStatus(422);

        // Sin reportes no se ven gastos ni utilidad
        $this->actuarComo('cajero');
        $this->getJson('/api/panel')->assertOk()->assertJsonMissingPath('panel.rango.utilidad_bruta');
    }
}
