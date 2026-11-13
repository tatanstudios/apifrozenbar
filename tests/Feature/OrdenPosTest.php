<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Producto;
use App\Models\UnidadesMedida;
use Database\Seeders\UnidadesMedidaSeeder;
use Tests\TestCase;

class OrdenPosTest extends TestCase
{
    private function producto(string $nombre, Categoria $cat, int $orden = 0): Producto
    {
        $p = Producto::create(['nombre' => $nombre, 'tipo' => 'bien', 'clase' => 'producto_terminado', 'precio_venta' => 1,
            'costo_unitario' => 0, 'orden_pos' => $orden, 'unidad_medida_id' => UnidadesMedida::where('nombre', 'Unidad')->value('id')]);
        $p->categorias()->attach($cat->id);

        return $p;
    }

    public function test_ordenar_arrastrando_reparte_las_posiciones_de_la_categoria(): void
    {
        $this->seed(UnidadesMedidaSeeder::class);
        $pan = Categoria::create(['nombre' => 'Pan', 'estado' => true]);
        $bebidas = Categoria::create(['nombre' => 'Bebidas', 'estado' => true]);

        // Empatados en 0: se numeran de 10 en 10
        $frances = $this->producto('Pan francés', $pan);
        $semita = $this->producto('Semita', $pan);
        $dulce = $this->producto('Pan dulce', $pan);

        $this->actuarComo('admin');
        $this->putJson('/api/productos/orden-pos', ['ids' => [$semita->id, $frances->id, $dulce->id]])->assertOk();
        $this->assertSame([10, 20, 30], [$semita->fresh()->orden_pos, $frances->fresh()->orden_pos, $dulce->fresh()->orden_pos]);

        // Con posiciones distintas se reusan las mismas: la categoría no se mueve respecto de las otras
        $cafe = $this->producto('Café', $bebidas, 15);
        $this->putJson('/api/productos/orden-pos', ['ids' => [$dulce->id, $semita->id, $frances->id]])->assertOk();
        $this->assertSame([10, 20, 30], [$dulce->fresh()->orden_pos, $semita->fresh()->orden_pos, $frances->fresh()->orden_pos]);
        $this->assertSame(15, $cafe->fresh()->orden_pos);

        $lista = $this->getJson('/api/productos/orden-pos')->assertOk()->assertJsonPath('paso', 10);
        $this->assertSame(['Pan dulce', 'Café', 'Semita', 'Pan francés'], collect($lista->json('productos'))->pluck('nombre')->all());
        $this->assertSame([$pan->id], collect($lista->json('productos'))->firstWhere('nombre', 'Semita')['categorias']);

        $this->actuarComo('cajero');
        $this->putJson('/api/productos/orden-pos', ['ids' => [$frances->id]])->assertStatus(403);
    }
}
