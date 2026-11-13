<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\ConfiguracionNegocio;
use App\Models\Inventario;
use App\Models\Merma;
use App\Models\Produccion;
use App\Models\Producto;
use App\Models\Receta;
use App\Models\UnidadesMedida;
use Database\Seeders\CajaSeeder;
use Database\Seeders\UnidadesMedidaSeeder;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProduccionTest extends TestCase
{
    private Producto $pan;
    private Producto $harina;   // en libras
    private Producto $levadura; // en gramos
    private array $u;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(UnidadesMedidaSeeder::class);
        $this->u = UnidadesMedida::pluck('id', 'nombre')->all();

        $this->pan = $this->producto('Pan francés', 'producto_terminado', 'Unidad', 0, 100, ['precio_venta' => 0.15]);
        $this->harina = $this->producto('Harina', 'materia_prima', 'Libra', 0.50, 100, ['permite_fraccion' => true]);
        $this->levadura = $this->producto('Levadura', 'materia_prima', 'Gramo', 0.02, 1000, ['permite_fraccion' => true]);
    }

    private function producto(string $nombre, string $clase, string $unidad, float $costo, float $stock, array $extra = []): Producto
    {
        $p = Producto::create(array_merge([
            'nombre' => $nombre, 'tipo' => 'bien', 'clase' => $clase, 'se_vende' => $clase === 'producto_terminado',
            'costo_unitario' => $costo, 'unidad_medida_id' => $this->u[$unidad],
        ], $extra));
        Inventario::create(['producto_id' => $p->id, 'stock_actual' => $stock]);
        return $p;
    }

    private function stock(Producto $p): float
    {
        return (float) Inventario::where('producto_id', $p->id)->value('stock_actual');
    }

    // 100 panes: 20 lb de harina (+5% de merma) y 200 g de levadura
    private function crearReceta(array $extra = [])
    {
        return $this->postJson('/api/produccion/recetas', array_merge([
            'producto_id' => $this->pan->id,
            'rendimiento' => 100,
            'ingredientes' => [
                ['producto_id' => $this->harina->id, 'unidad_medida_id' => $this->u['Libra'], 'cantidad' => 20, 'merma_porcentaje' => 5],
                ['producto_id' => $this->levadura->id, 'unidad_medida_id' => $this->u['Kilogramo'], 'cantidad' => 0.2],
            ],
        ], $extra));
    }

    private function producir(int $recetaId, array $extra = [])
    {
        return $this->postJson('/api/produccion', array_merge([
            'uuid' => (string) Str::uuid(), 'receta_id' => $recetaId, 'cantidad_producida' => 100,
        ], $extra));
    }

    // ── Recetas ───────────────────────────────────────────────

    public function test_receta_calcula_costo_con_conversion_y_merma(): void
    {
        $this->actuarComo('encargado_inventario');

        // Harina: 21 lb x 0.50 = 10.50; levadura: 200 g x 0.02 = 4.00 → $14.50 / 100 = $0.145 por pan
        $this->crearReceta()->assertCreated()
            ->assertJsonPath('receta.version', 1)
            ->assertJsonPath('receta.costo_total', 14.5)
            ->assertJsonPath('receta.costo_unitario', 0.145)
            ->assertJsonPath('receta.ingredientes.1.cantidad_base', 200)
            ->assertJsonPath('receta.margen', 3.3);
    }

    public function test_receta_valida_unidades_y_una_activa_por_producto(): void
    {
        $this->actuarComo('encargado_inventario');

        $this->crearReceta(['ingredientes' => [['producto_id' => $this->harina->id, 'unidad_medida_id' => $this->u['Litro'], 'cantidad' => 1]]])
            ->assertStatus(422)->assertJsonPath('message', 'No se puede convertir de Litro (volumen) a Libra (masa)');

        $this->crearReceta(['ingredientes' => [['producto_id' => $this->pan->id, 'unidad_medida_id' => $this->u['Unidad'], 'cantidad' => 1]]])
            ->assertStatus(422)->assertJsonPath('message', 'Un producto no puede ser ingrediente de su propia receta');

        $this->crearReceta()->assertCreated();
        $this->crearReceta()->assertStatus(422)->assertJsonPath('message', 'Pan francés ya tiene una receta activa: edítala para cambiarla');
    }

    public function test_editar_receta_usada_crea_version_nueva(): void
    {
        $this->actuarComo('admin');
        $id = $this->crearReceta()->json('receta.id');

        // Sin producciones se edita en el lugar
        $this->putJson("/api/produccion/recetas/{$id}", ['producto_id' => $this->pan->id, 'rendimiento' => 110,
            'ingredientes' => [['producto_id' => $this->harina->id, 'unidad_medida_id' => $this->u['Libra'], 'cantidad' => 20]]])
            ->assertOk()->assertJsonPath('receta.id', $id)->assertJsonPath('receta.version', 1);

        $this->producir($id, ['cantidad_producida' => 110])->assertCreated();

        $nueva = $this->putJson("/api/produccion/recetas/{$id}", ['producto_id' => $this->pan->id, 'rendimiento' => 120,
            'ingredientes' => [['producto_id' => $this->harina->id, 'unidad_medida_id' => $this->u['Libra'], 'cantidad' => 22]]])
            ->assertOk()->assertJsonPath('receta.version', 2)->json('receta');

        $this->assertNotSame($id, $nueva['id']);
        $this->assertFalse(Receta::find($id)->activa);
        $this->assertCount(2, $nueva['versiones']);

        // Se puede volver a la versión anterior
        $this->postJson("/api/produccion/recetas/{$id}/activar", ['activa' => true])->assertOk();
        $this->assertFalse(Receta::find($nueva['id'])->activa);
    }

    // ── Producción ────────────────────────────────────────────

    public function test_produccion_descuenta_insumos_y_suma_pan_a_su_costo(): void
    {
        $this->actuarComo('encargado_inventario');
        $receta = $this->crearReceta()->json('receta.id');

        $this->producir($receta, ['cantidad_planificada' => 200, 'cantidad_producida' => 190])->assertCreated()
            ->assertJsonPath('produccion.numero', 'PR-000001')
            ->assertJsonPath('produccion.rendimiento', 95)
            ->assertJsonPath('produccion.costo_total', 29)
            ->assertJsonPath('produccion.consumos.0.cantidad_teorica', 42);

        $this->assertEquals(58, $this->stock($this->harina));     // 100 - 42 lb
        $this->assertEquals(600, $this->stock($this->levadura));  // 1000 - 400 g
        $this->assertEquals(290, $this->stock($this->pan));

        // El pan entra a $29 / 190 y se promedia con el que había (a costo 0)
        $this->assertEqualsWithDelta(29 / 290, (float) $this->pan->fresh()->costo_unitario, 0.0001);
        $this->assertDatabaseHas('movimientos_inventario', ['tipo' => 'consumo_produccion', 'producto_id' => $this->harina->id, 'referencia_tipo' => 'produccion']);
    }

    public function test_consumo_real_distinto_al_teorico(): void
    {
        $this->actuarComo('encargado_inventario');
        $receta = $this->crearReceta()->json('receta.id');

        $this->producir($receta, ['consumos' => [['producto_id' => $this->harina->id, 'cantidad_real' => 23]]])
            ->assertCreated()
            ->assertJsonPath('produccion.consumos.0.cantidad_teorica', 21)
            ->assertJsonPath('produccion.consumos.0.diferencia', 2);

        $this->assertEquals(77, $this->stock($this->harina));
    }

    public function test_sin_insumos_suficientes_no_se_produce_nada(): void
    {
        ConfiguracionNegocio::actual()->update(['permitir_stock_negativo' => false]);
        $this->actuarComo('encargado_inventario');
        $receta = $this->crearReceta()->json('receta.id');

        $this->producir($receta, ['cantidad_planificada' => 1000, 'cantidad_producida' => 1000])->assertStatus(422);

        $this->assertSame(0, Produccion::count());
        $this->assertEquals(100, $this->stock($this->harina));
        $this->assertEquals(100, $this->stock($this->pan));
    }

    public function test_anular_produccion_revierte_inventario(): void
    {
        $this->actuarComo('admin');
        $receta = $this->crearReceta()->json('receta.id');
        $uuid = (string) Str::uuid();
        $id = $this->producir($receta, ['uuid' => $uuid])->json('produccion.id');
        $this->producir($receta, ['uuid' => $uuid])->assertOk(); // reintento sin duplicar

        $this->postJson("/api/produccion/{$id}/anular", ['motivo' => 'Se registró dos veces'])->assertOk()
            ->assertJsonPath('produccion.estado', 'anulada');

        $this->assertEquals(100, $this->stock($this->harina));
        $this->assertEquals(1000, $this->stock($this->levadura));
        $this->assertEquals(100, $this->stock($this->pan));
    }

    // ── Mermas ────────────────────────────────────────────────

    public function test_mermas_del_cierre_del_dia(): void
    {
        $this->pan->update(['costo_unitario' => 0.10]);
        $this->actuarComo('encargado_inventario');
        $items = [
            ['uuid' => (string) Str::uuid(), 'producto_id' => $this->pan->id, 'cantidad' => 12, 'motivo' => 'vencido'],
            ['uuid' => (string) Str::uuid(), 'producto_id' => $this->pan->id, 'cantidad' => 3, 'motivo' => 'quemado', 'detalle' => 'Horno 2'],
            ['uuid' => (string) Str::uuid(), 'producto_id' => $this->harina->id, 'cantidad' => 0, 'motivo' => 'danado'], // sin cantidad: se ignora
        ];

        $this->postJson('/api/mermas', ['items' => $items])->assertCreated()
            ->assertJsonCount(2, 'mermas')
            ->assertJsonPath('mermas.0.costo_total', 1.2);
        $this->postJson('/api/mermas', ['items' => $items])->assertCreated(); // reintento

        $this->assertSame(2, Merma::count());
        $this->assertEquals(85, $this->stock($this->pan));

        $id = Merma::where('motivo', 'quemado')->value('id');
        $this->postJson("/api/mermas/{$id}/anular", ['motivo' => 'Se vendió como pan tostado'])->assertOk();
        $this->assertEquals(88, $this->stock($this->pan));
    }

    public function test_resumen_de_produccion_y_mermas(): void
    {
        $this->actuarComo('admin');
        $receta = $this->crearReceta()->json('receta.id');
        $this->producir($receta, ['cantidad_planificada' => 100, 'cantidad_producida' => 90]);
        $this->postJson('/api/mermas', ['items' => [['uuid' => (string) Str::uuid(), 'producto_id' => $this->pan->id, 'cantidad' => 10, 'motivo' => 'vencido']]]);

        $this->getJson('/api/produccion/resumen')->assertOk()
            ->assertJsonPath('resumen.totales.producciones', 1)
            ->assertJsonPath('resumen.totales.mermas', 1)
            ->assertJsonPath('resumen.por_producto.0.rendimiento', 90)
            ->assertJsonPath('resumen.mermas.por_motivo.0.motivo', 'vencido');
    }

    public function test_lo_registrado_queda_en_el_turno_abierto_y_sale_en_reportes(): void
    {
        $this->seed(CajaSeeder::class);
        $this->actuarComo('admin', ['nombre' => 'Ana']);
        $merma = fn () => $this->postJson('/api/mermas', ['items' => [['uuid' => (string) Str::uuid(), 'producto_id' => $this->pan->id, 'cantidad' => 2, 'motivo' => 'vencido']]]);

        // Sin turno abierto el registro queda sin turno
        $merma()->assertCreated();
        $this->assertNull(Merma::first()->turno_id);

        $turnoId = $this->postJson('/api/caja/turnos/abrir', ['caja_id' => Caja::first()->id, 'fondo_inicial' => 20])->json('turno.id');
        $merma()->assertCreated();
        $receta = $this->crearReceta()->json('receta.id');
        $this->producir($receta)->assertCreated();

        $this->assertSame($turnoId, Merma::latest('id')->first()->turno_id);
        $this->assertSame($turnoId, Produccion::first()->turno_id);
        $this->assertDatabaseHas('movimientos_inventario', ['producto_id' => $this->harina->id, 'turno_id' => $turnoId]);

        $this->postJson("/api/caja/turnos/{$turnoId}/cerrar", ['efectivo_contado' => 20])->assertOk();

        $filas = collect($this->getJson('/api/reportes/mermas')->assertOk()->json('reporte.filas'));
        $this->assertStringStartsWith("#{$turnoId} · abrió Ana", $filas->firstWhere('turno', '!=', null)['turno']);
        $this->assertStringContainsString('cerró Ana', $filas->firstWhere('turno', '!=', null)['turno']);
        $this->assertSame(1, $filas->whereNull('turno')->count());

        $registros = collect($this->getJson("/api/caja/turnos/{$turnoId}")->assertOk()->json('registros'));
        $this->assertEqualsCanonicalizing(['Merma', 'Producción'], $registros->pluck('tipo')->all());
        $this->assertSame('Ana', $registros->first()['usuario']);
    }

    public function test_permisos_de_produccion(): void
    {
        $this->actuarComo('cajero');
        $this->getJson('/api/produccion/recetas')->assertStatus(403);
        $this->postJson('/api/mermas', ['items' => []])->assertStatus(403);

        $this->actuarComo('encargado_inventario');
        $this->getJson('/api/produccion/recetas')->assertOk();
        $this->getJson('/api/produccion/catalogo')->assertOk();
    }

    public function test_sin_permiso_de_receta_produce_pero_no_ve_ingredientes(): void
    {
        $this->actuarComo('admin');
        $receta = $this->crearReceta(['notas' => 'Amasar 10 minutos'])->json('receta.id');

        $mesero = $this->actuarComo('mesero');
        $this->assertFalse($mesero->can('produccion.ver_receta'));

        $this->getJson("/api/produccion/recetas/{$receta}")->assertOk()
            ->assertJsonPath('receta.ingredientes_ocultos', true)
            ->assertJsonPath('receta.ingredientes', [])
            ->assertJsonPath('receta.notas', null)
            ->assertJsonPath('receta.rendimiento', 100);

        // Produce igual: se descuenta lo de la receta aunque no la vea
        $id = $this->producir($receta)->assertCreated()
            ->assertJsonPath('produccion.ingredientes_ocultos', true)
            ->assertJsonPath('produccion.consumos', [])
            ->json('produccion.id');
        $this->assertEquals(79, $this->stock($this->harina)); // 100 - 21 lb

        $this->getJson("/api/produccion/{$id}")->assertJsonPath('produccion.consumos', []);
        $this->getJson('/api/produccion/resumen')->assertJsonPath('resumen.ingredientes_ocultos', true)->assertJsonPath('resumen.consumos', []);

        // Con el permiso ve los ingredientes
        $mesero->givePermissionTo('produccion.ver_receta');
        $this->getJson("/api/produccion/recetas/{$receta}")
            ->assertJsonPath('receta.ingredientes_ocultos', false)
            ->assertJsonPath('receta.notas', 'Amasar 10 minutos')
            ->assertJsonCount(2, 'receta.ingredientes');
        $this->getJson("/api/produccion/{$id}")->assertJsonCount(2, 'produccion.consumos');
    }
}
