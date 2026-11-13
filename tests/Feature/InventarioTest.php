<?php

namespace Tests\Feature;

use App\Models\ConfiguracionNegocio;
use App\Models\Inventario;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\UnidadesMedida;
use Tests\TestCase;

class InventarioTest extends TestCase
{
    private function crearProducto(float $stock = 0): Producto
    {
        $unidad = UnidadesMedida::create(['nombre' => 'Unidad', 'estado' => true]);

        $producto = Producto::create([
            'nombre'           => 'Pan francés',
            'tipo'             => 'bien',
            'costo_unitario'   => 0.05,
            'unidad_medida_id' => $unidad->id,
        ]);

        Inventario::create(['producto_id' => $producto->id, 'stock_actual' => $stock, 'stock_minimo' => 0]);

        return $producto;
    }

    private function registrar(Producto $producto, string $tipo, float $cantidad)
    {
        return $this->postJson('/api/inventario/entradas/registrar', [
            'fecha' => now()->toDateString(),
            'tipo'  => $tipo,
            'items' => [['producto_id' => $producto->id, 'cantidad' => $cantidad]],
        ]);
    }

    public function test_entrada_aumenta_stock_y_registra_usuario_y_auditoria(): void
    {
        $usuario  = $this->actuarComo('encargado_inventario');
        $producto = $this->crearProducto(10);

        $this->registrar($producto, 'entrada', 5)->assertOk()->assertJsonPath('success', 1);

        $this->assertEquals(15, (float) $producto->inventario()->first()->stock_actual);

        $movimiento = MovimientoInventario::first();
        $this->assertSame($usuario->id, $movimiento->usuario_id);
        $this->assertSame('entrada_inventario', $movimiento->referencia_tipo);
        $this->assertEquals(10, (float) $movimiento->stock_anterior);

        $this->assertDatabaseHas('auditorias', ['modulo' => 'inventario', 'accion' => 'entrada', 'usuario_id' => $usuario->id]);
    }

    public function test_listado_de_entradas_muestra_nombre_del_usuario(): void
    {
        $this->actuarComo('encargado_inventario', ['nombre' => 'Rosa Encargada']);
        $producto = $this->crearProducto(0);
        $this->registrar($producto, 'entrada', 3)->assertOk();

        $this->getJson('/api/inventario/entradas')
            ->assertOk()
            ->assertJsonPath('0.usuario', 'Rosa Encargada');
    }

    public function test_salida_bloqueada_si_no_se_permite_stock_negativo(): void
    {
        ConfiguracionNegocio::actual()->update(['permitir_stock_negativo' => false]);
        $this->actuarComo('encargado_inventario');
        $producto = $this->crearProducto(2);

        $this->registrar($producto, 'salida', 5)
            ->assertStatus(422)
            ->assertJsonPath('status', 'BUSINESS_RULE');

        // Nada cambió: la transacción se revirtió completa
        $this->assertEquals(2, (float) $producto->inventario()->first()->stock_actual);
        $this->assertSame(0, MovimientoInventario::count());
    }

    public function test_salida_permitida_con_stock_negativo_si_la_configuracion_lo_permite(): void
    {
        $this->actuarComo('encargado_inventario');
        $producto = $this->crearProducto(2);

        $this->registrar($producto, 'salida', 5)->assertOk();

        $this->assertEquals(-3, (float) $producto->inventario()->first()->stock_actual);
    }

    public function test_cajero_no_registra_ni_ajusta_inventario(): void
    {
        $this->actuarComo('cajero'); // tiene inventario.ver, no registrar ni ajustar
        $producto = $this->crearProducto(2);

        $this->registrar($producto, 'ajuste', 10)->assertStatus(403);
        $this->registrar($producto, 'entrada', 10)->assertStatus(403);
    }

    public function test_ajuste_sin_permiso_de_ajustar_se_rechaza(): void
    {
        $usuario = $this->actuarComo('encargado_inventario');
        $usuario->roles()->first()->revokePermissionTo('inventario.ajustar');
        $producto = $this->crearProducto(2);

        $this->registrar($producto, 'ajuste', 10)->assertStatus(403);
        $this->registrar($producto, 'entrada', 1)->assertOk();
    }

    public function test_crear_producto_crea_su_fila_de_inventario(): void
    {
        $this->actuarComo('encargado_inventario');
        $unidad = UnidadesMedida::create(['nombre' => 'Unidad', 'estado' => true]);

        $id = $this->postJson('/api/productos/registro', [
            'nombre'           => 'Concha',
            'clase'            => 'producto_terminado',
            'se_vende'         => true,
            'controla_stock'   => true,
            'permite_fraccion' => false,
            'costo_unitario'   => 0.10,
            'precio_venta'     => 0.35,
            'stock_minimo'     => 20,
            'unidad_medida_id' => $unidad->id,
        ])->assertOk()->json('id');

        $this->assertDatabaseHas('inventario', ['producto_id' => $id, 'stock_actual' => 0, 'stock_minimo' => 20]);
        $this->assertDatabaseHas('productos', ['id' => $id, 'tipo' => 'bien', 'precio_venta' => 0.35]);
        $this->assertDatabaseHas('auditorias', ['modulo' => 'productos', 'accion' => 'crear', 'entidad_id' => $id]);
    }
}
