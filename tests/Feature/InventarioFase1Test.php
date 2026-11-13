<?php

namespace Tests\Feature;

use App\Exceptions\NegocioException;
use App\Models\Inventario;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\UnidadesMedida;
use Database\Seeders\UnidadesMedidaSeeder;
use Tests\TestCase;

class InventarioFase1Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(UnidadesMedidaSeeder::class);
    }

    private function unidad(string $nombre): UnidadesMedida
    {
        return UnidadesMedida::where('nombre', $nombre)->firstOrFail();
    }

    private function crearProducto(array $atributos = [], float $stock = 0, float $minimo = 0): Producto
    {
        $producto = Producto::create(array_merge([
            'nombre'           => 'Pan francés',
            'tipo'             => 'bien',
            'clase'            => 'producto_terminado',
            'costo_unitario'   => 1,
            'precio_venta'     => 1.5,
            'unidad_medida_id' => $this->unidad('Unidad')->id,
        ], $atributos));

        Inventario::create(['producto_id' => $producto->id, 'stock_actual' => $stock, 'stock_minimo' => $minimo]);

        return $producto;
    }

    private function registrar(Producto $producto, string $tipo, float $cantidad, ?float $costo = null, ?string $descripcion = 'Prueba')
    {
        return $this->postJson('/api/inventario/entradas/registrar', [
            'fecha'       => now()->toDateString(),
            'tipo'        => $tipo,
            'descripcion' => $descripcion,
            'items'       => [['producto_id' => $producto->id, 'cantidad' => $cantidad, 'costo_unitario' => $costo]],
        ]);
    }

    // ── Costo promedio ponderado ────────────────────────────────

    public function test_entrada_con_costo_recalcula_costo_promedio(): void
    {
        $this->actuarComo('encargado_inventario');
        $producto = $this->crearProducto(['costo_unitario' => 1.00], stock: 10);

        $this->registrar($producto, 'entrada', 10, 2.00)->assertOk();

        $this->assertEquals(1.5, (float) $producto->fresh()->costo_unitario);

        $mov = MovimientoInventario::latest('id')->first();
        $this->assertEquals(2.00, (float) $mov->costo_unitario);
        $this->assertEquals(20.00, (float) $mov->costo_total);
    }

    public function test_entrada_sin_costo_no_cambia_el_promedio(): void
    {
        $this->actuarComo('encargado_inventario');
        $producto = $this->crearProducto(['costo_unitario' => 1.25], stock: 4);

        $this->registrar($producto, 'entrada', 4)->assertOk();

        $this->assertEquals(1.25, (float) $producto->fresh()->costo_unitario);
        $this->assertEquals(5.00, (float) MovimientoInventario::latest('id')->first()->costo_total);
    }

    public function test_stock_negativo_no_aporta_al_costo_promedio(): void
    {
        $this->actuarComo('encargado_inventario');
        $producto = $this->crearProducto(['costo_unitario' => 5.00], stock: -3);

        $this->registrar($producto, 'entrada', 10, 2.00)->assertOk();

        // El negativo no tenía costo real: el nuevo promedio es el de la compra
        $this->assertEquals(2.00, (float) $producto->fresh()->costo_unitario);
        $this->assertEquals(7, (float) $producto->inventario()->first()->stock_actual);
    }

    public function test_salida_se_valoriza_al_costo_promedio(): void
    {
        $this->actuarComo('encargado_inventario');
        $producto = $this->crearProducto(['costo_unitario' => 0.80], stock: 10);

        $this->registrar($producto, 'salida', 3, 99)->assertOk(); // el costo enviado se ignora en salidas

        $mov = MovimientoInventario::latest('id')->first();
        $this->assertEquals(0.80, (float) $mov->costo_unitario);
        $this->assertEquals(2.40, (float) $mov->costo_total);
        $this->assertEquals(0.80, (float) $producto->fresh()->costo_unitario);
    }

    // ── Ajustes y reglas de cantidad ────────────────────────────

    public function test_ajuste_por_conteo_puede_dejar_el_stock_en_cero(): void
    {
        $this->actuarComo('encargado_inventario');
        $producto = $this->crearProducto(stock: 12);

        $this->registrar($producto, 'ajuste', 0, descripcion: 'Conteo de cierre')->assertOk();

        $this->assertEquals(0, (float) $producto->inventario()->first()->stock_actual);
        $this->assertEquals(-12, MovimientoInventario::latest('id')->first()->diferencia());
    }

    public function test_ajuste_exige_motivo(): void
    {
        $this->actuarComo('encargado_inventario');
        $producto = $this->crearProducto(stock: 12);

        $this->registrar($producto, 'ajuste', 10, descripcion: null)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Indica el motivo del ajuste');
    }

    public function test_entrada_con_cantidad_cero_se_rechaza(): void
    {
        $this->actuarComo('encargado_inventario');
        $producto = $this->crearProducto();

        $this->registrar($producto, 'entrada', 0)
            ->assertStatus(422)
            ->assertJsonPath('message', 'La cantidad debe ser mayor a 0');
    }

    public function test_fecha_futura_se_rechaza(): void
    {
        $this->actuarComo('encargado_inventario');
        $producto = $this->crearProducto();

        $this->postJson('/api/inventario/entradas/registrar', [
            'fecha' => now()->addDays(2)->toDateString(),
            'tipo'  => 'entrada',
            'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ])->assertStatus(422);
    }

    public function test_producto_por_unidad_no_acepta_decimales(): void
    {
        $this->actuarComo('encargado_inventario');
        $pan    = $this->crearProducto(['permite_fraccion' => false]);
        $harina = $this->crearProducto([
            'nombre' => 'Harina', 'clase' => 'materia_prima', 'permite_fraccion' => true,
            'unidad_medida_id' => $this->unidad('Libra')->id,
        ]);

        $this->registrar($pan, 'entrada', 1.5)
            ->assertStatus(422)->assertJsonPath('status', 'BUSINESS_RULE');
        $this->registrar($harina, 'entrada', 1.5)->assertOk();
    }

    public function test_producto_sin_control_de_stock_no_mueve_inventario(): void
    {
        $this->actuarComo('encargado_inventario');
        $servicio = $this->crearProducto(['nombre' => 'Decoración', 'controla_stock' => false]);

        $this->registrar($servicio, 'entrada', 1)
            ->assertStatus(422)->assertJsonPath('status', 'BUSINESS_RULE');
    }

    // ── Stock, alertas y stock mínimo ───────────────────────────

    public function test_alertas_incluyen_bajo_minimo_y_negativos_pero_no_sin_minimo(): void
    {
        $this->actuarComo('cajero');
        $bajo     = $this->crearProducto(['nombre' => 'Bajo'], stock: 3, minimo: 5);
        $justo    = $this->crearProducto(['nombre' => 'Justo'], stock: 5, minimo: 5);
        $negativo = $this->crearProducto(['nombre' => 'Negativo'], stock: -2);
        $this->crearProducto(['nombre' => 'Sin minimo'], stock: 0);
        $this->crearProducto(['nombre' => 'Sobrado'], stock: 50, minimo: 5);
        $this->crearProducto(['nombre' => 'Inactivo', 'activo' => false], stock: 0, minimo: 5);

        $res = $this->getJson('/api/inventario/alertas')->assertOk();

        $this->assertSame(3, $res->json('total'));
        $this->assertEqualsCanonicalizing(
            [$bajo->id, $justo->id, $negativo->id],
            array_column($res->json('productos'), 'producto_id')
        );
    }

    public function test_stock_filtra_por_clase_y_estado_y_excluye_servicios(): void
    {
        $this->actuarComo('encargado_inventario');
        $this->crearProducto(['nombre' => 'Pan'], stock: 1, minimo: 5);
        $this->crearProducto(['nombre' => 'Harina', 'clase' => 'materia_prima'], stock: 100, minimo: 5);
        $this->crearProducto(['nombre' => 'Servicio', 'controla_stock' => false]);

        $this->assertCount(2, $this->getJson('/api/inventario/stock')->json());
        $this->assertSame(['Harina'], array_column($this->getJson('/api/inventario/stock?clase=materia_prima')->json(), 'producto'));
        $this->assertSame(['Pan'], array_column($this->getJson('/api/inventario/stock?estado=alerta')->json(), 'producto'));
    }

    public function test_actualizar_stock_minimo_requiere_permiso(): void
    {
        $producto = $this->crearProducto(stock: 3);

        $this->actuarComo('cajero');
        $this->putJson("/api/inventario/stock-minimo/{$producto->id}", ['stock_minimo' => 10])->assertStatus(403);

        $this->actuarComo('encargado_inventario');
        $this->putJson("/api/inventario/stock-minimo/{$producto->id}", ['stock_minimo' => 10])
            ->assertOk()
            ->assertJsonPath('bajo_minimo', true);

        $this->assertEquals(10, (float) $producto->inventario()->first()->stock_minimo);
        $this->assertDatabaseHas('auditorias', ['accion' => 'stock_minimo', 'entidad_id' => $producto->id]);
    }

    // ── Kardex e historial ──────────────────────────────────────

    public function test_kardex_muestra_saldo_inicial_movimientos_y_totales(): void
    {
        $this->actuarComo('encargado_inventario');
        $producto = $this->crearProducto(['costo_unitario' => 1]);

        // Movimiento anterior al período consultado
        $this->registrar($producto, 'entrada', 20)->assertOk();
        MovimientoInventario::query()->update(['created_at' => now()->subDays(10)]);

        $this->registrar($producto, 'entrada', 5)->assertOk();
        $this->registrar($producto, 'salida', 8)->assertOk();
        $this->registrar($producto, 'ajuste', 15, descripcion: 'Conteo')->assertOk();

        $res = $this->getJson("/api/inventario/kardex/{$producto->id}?desde=" . now()->subDays(2)->toDateString())
            ->assertOk();

        $this->assertEquals(20, $res->json('saldo_inicial'));
        $this->assertEquals(5, $res->json('total_entradas'));
        $this->assertEquals(10, $res->json('total_salidas')); // 8 de la salida + 2 del ajuste (17 → 15)
        $this->assertEquals(15, $res->json('saldo_final'));
        $this->assertSame(3, $res->json('total'));

        $filas = $res->json('movimientos');
        $this->assertSame(['entrada', 'salida', 'ajuste'], array_column($filas, 'tipo'));
        $this->assertEquals([25, 17, 15], array_column($filas, 'saldo'));
        $this->assertEquals(2, $filas[2]['salida']);
        $this->assertStringStartsWith('Registro de inventario #', $filas[0]['referencia']);
    }

    public function test_kardex_requiere_permiso_de_kardex(): void
    {
        $producto = $this->crearProducto();
        $this->actuarComo('cajero');

        $this->getJson("/api/inventario/kardex/{$producto->id}")->assertStatus(403);
    }

    public function test_historial_de_movimientos_filtra_por_tipo_y_producto(): void
    {
        $this->actuarComo('encargado_inventario');
        $pan    = $this->crearProducto(['nombre' => 'Pan']);
        $harina = $this->crearProducto(['nombre' => 'Harina']);

        $this->registrar($pan, 'entrada', 5)->assertOk();
        $this->registrar($pan, 'salida', 1)->assertOk();
        $this->registrar($harina, 'entrada', 3)->assertOk();

        $this->assertSame(3, $this->getJson('/api/inventario/movimientos')->json('total'));
        $this->assertSame(2, $this->getJson('/api/inventario/movimientos?tipo=entrada')->json('total'));
        $this->assertSame(
            ['Pan', 'Pan'],
            array_column($this->getJson("/api/inventario/movimientos?producto_id={$pan->id}")->json('movimientos'), 'producto')
        );
    }

    // ── Productos: precios y campos nuevos ──────────────────────

    private function datosProducto(Producto $p, array $cambios = []): array
    {
        return array_merge([
            'nombre'           => $p->nombre,
            'clase'            => $p->clase,
            'se_vende'         => true,
            'controla_stock'   => true,
            'permite_fraccion' => false,
            'costo_unitario'   => (float) $p->costo_unitario,
            'precio_venta'     => (float) $p->precio_venta,
            'unidad_medida_id' => $p->unidad_medida_id,
        ], $cambios);
    }

    public function test_cambiar_precio_requiere_permiso_de_precios(): void
    {
        $producto = $this->crearProducto(['costo_unitario' => 0.10, 'precio_venta' => 0.25]);

        $this->actuarComo('encargado_inventario');
        $this->postJson("/api/productos/actualizar/{$producto->id}", $this->datosProducto($producto, ['precio_venta' => 0.30]))
            ->assertStatus(403);

        // Sin tocar precio ni costo sí puede editar
        $this->postJson("/api/productos/actualizar/{$producto->id}", $this->datosProducto($producto, ['nombre' => 'Pan francés grande']))
            ->assertOk();

        $this->actuarComo('admin');
        $this->postJson("/api/productos/actualizar/{$producto->id}", $this->datosProducto($producto->fresh(), ['precio_venta' => 0.30]))
            ->assertOk();

        $this->assertEquals(0.30, (float) $producto->fresh()->precio_venta);
        $this->assertDatabaseHas('auditorias', ['accion' => 'cambio_precio', 'entidad_id' => $producto->id]);
    }

    public function test_producto_sin_inventario_se_guarda_como_servicio(): void
    {
        $this->actuarComo('admin');

        $id = $this->postJson('/api/productos/registro', [
            'nombre'           => 'Decoración de pastel',
            'clase'            => 'producto_terminado',
            'se_vende'         => true,
            'controla_stock'   => false,
            'permite_fraccion' => false,
            'costo_unitario'   => 0,
            'precio_venta'     => 5,
            'color_pos'        => '#F59E0B',
            'unidad_medida_id' => $this->unidad('Unidad')->id,
        ])->assertOk()->json('id');

        $this->assertDatabaseHas('productos', ['id' => $id, 'tipo' => 'servicio', 'color_pos' => '#F59E0B']);
    }

    public function test_codigo_barras_no_se_repite_y_color_valida_formato(): void
    {
        $this->actuarComo('admin');
        $this->crearProducto(['codigo_barras' => '7401234567890']);

        $base = [
            'nombre' => 'Otro', 'clase' => 'reventa', 'se_vende' => true, 'controla_stock' => true,
            'permite_fraccion' => false, 'costo_unitario' => 0.5, 'precio_venta' => 1,
            'unidad_medida_id' => $this->unidad('Unidad')->id,
        ];

        $this->postJson('/api/productos/registro', $base + ['codigo_barras' => '7401234567890'])
            ->assertStatus(422)->assertJsonValidationErrors('codigo_barras');
        $this->postJson('/api/productos/registro', $base + ['color_pos' => 'rojo'])
            ->assertStatus(422)->assertJsonValidationErrors('color_pos');
    }

    // ── Unidades de medida ──────────────────────────────────────

    public function test_conversion_entre_unidades_del_mismo_tipo(): void
    {
        $this->assertEqualsWithDelta(907.18474, $this->unidad('Libra')->convertirA(2, $this->unidad('Gramo')), 0.0001);
        $this->assertEqualsWithDelta(100, $this->unidad('Quintal')->convertirA(1, $this->unidad('Libra')), 0.0001);
        $this->assertEqualsWithDelta(1.5, $this->unidad('Mililitro')->convertirA(1500, $this->unidad('Litro')), 0.0001);
        $this->assertEqualsWithDelta(24, $this->unidad('Docena')->convertirA(2, $this->unidad('Unidad')), 0.0001);
    }

    public function test_no_se_convierte_entre_tipos_distintos(): void
    {
        $this->expectException(NegocioException::class);
        $this->unidad('Libra')->convertirA(1, $this->unidad('Litro'));
    }

    public function test_registrar_unidad_con_conversion(): void
    {
        $this->actuarComo('encargado_inventario');

        $this->postJson('/api/admin/unidadmedida/nuevo', [
            'nombre' => 'Bolsa 25 lb', 'abreviatura' => 'bls', 'tipo' => 'masa', 'factor_base' => 11339.80925,
        ])->assertOk();

        $bolsa = UnidadesMedida::where('nombre', 'Bolsa 25 lb')->first();
        $this->assertEqualsWithDelta(25, $bolsa->convertirA(1, $this->unidad('Libra')), 0.0001);

        // La pantalla anterior solo envía nombre: queda como unidad con factor 1
        $this->postJson('/api/admin/unidadmedida/nuevo', ['nombre' => 'Caja'])->assertOk();
        $this->assertDatabaseHas('unidades_medida', ['nombre' => 'Caja', 'tipo' => 'unidad', 'factor_base' => 1]);
    }
}
