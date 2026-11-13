<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Producto;
use App\Models\UnidadesMedida;
use App\Services\InventarioService;
use Database\Seeders\UnidadesMedidaSeeder;
use Tests\TestCase;

class ProductosBorrarTest extends TestCase
{
    private function producto(string $nombre): Producto
    {
        return Producto::create([
            'nombre' => $nombre, 'tipo' => 'bien', 'clase' => 'producto_terminado', 'controla_stock' => true,
            'precio_venta' => 2, 'costo_unitario' => 0, 'unidad_medida_id' => UnidadesMedida::value('id'),
        ]);
    }

    public function test_solo_se_borra_el_producto_que_nunca_se_uso(): void
    {
        $admin = $this->actuarComo('admin');
        $this->seed(UnidadesMedidaSeeder::class);
        $libre = $this->producto('Flan de prueba');
        $libre->categorias()->attach(Categoria::create(['nombre' => 'Postres', 'estado' => true])->id);
        $usado = $this->producto('Frappé de vainilla');
        InventarioService::aplicarMovimiento(productoId: $usado->id, tipo: 'entrada', cantidad: 3, motivo: 'Prueba', usuarioId: $admin->id);

        $tabla = collect($this->getJson('/api/productos/tabla')->assertOk()->json())->keyBy('id');
        $this->assertFalse($tabla[$libre->id]['usado']);
        $this->assertTrue($tabla[$usado->id]['usado']);

        $this->deleteJson("/api/productos/{$usado->id}")->assertStatus(422)->assertJsonPath('success', 0);
        $this->assertDatabaseHas('productos', ['id' => $usado->id]);

        $this->deleteJson("/api/productos/{$libre->id}")->assertOk();
        $this->assertDatabaseMissing('productos', ['id' => $libre->id]);
        $this->assertDatabaseMissing('categoria_producto', ['producto_id' => $libre->id]);
        $this->assertDatabaseHas('auditorias', ['modulo' => 'productos', 'accion' => 'borrar']);
    }

    public function test_sin_permiso_de_productos_no_se_borra(): void
    {
        $this->seed(UnidadesMedidaSeeder::class);
        $producto = $this->producto('Brownie');
        $this->actuarComo('mesero');

        $this->deleteJson("/api/productos/{$producto->id}")->assertForbidden();
        $this->assertDatabaseHas('productos', ['id' => $producto->id]);
    }
}
