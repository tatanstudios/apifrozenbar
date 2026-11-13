<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Producto;
use App\Models\UnidadesMedida;
use Database\Seeders\UnidadesMedidaSeeder;
use Tests\TestCase;

class CategoriasTest extends TestCase
{
    public function test_se_borra_una_categoria_que_nadie_usa(): void
    {
        $this->actuarComo('admin');
        $categoria = Categoria::create(['nombre' => 'De prueba', 'estado' => true]);

        $this->getJson('/api/admin/categorias/tabla')->assertOk()
            ->assertJsonFragment(['id' => $categoria->id, 'productos_count' => 0]);

        $this->deleteJson("/api/admin/categorias/{$categoria->id}")->assertOk();
        $this->assertDatabaseMissing('categorias', ['id' => $categoria->id]);
        $this->assertDatabaseHas('auditorias', ['accion' => 'borrar_categoria']);
    }

    public function test_no_se_borra_si_algun_producto_la_usa_aunque_este_inactivo(): void
    {
        $this->actuarComo('admin');
        $this->seed(UnidadesMedidaSeeder::class);
        $categoria = Categoria::create(['nombre' => 'Bebidas', 'estado' => true]);
        $producto = Producto::create([
            'nombre' => 'Café', 'tipo' => 'bien', 'clase' => 'producto_terminado', 'activo' => false,
            'precio_venta' => 1, 'costo_unitario' => 0.3, 'unidad_medida_id' => UnidadesMedida::value('id'),
        ]);
        $producto->categorias()->attach($categoria->id);

        $this->deleteJson("/api/admin/categorias/{$categoria->id}")
            ->assertStatus(422)
            ->assertJsonPath('success', 0);
        $this->assertDatabaseHas('categorias', ['id' => $categoria->id]);
        $this->assertDatabaseHas('categoria_producto', ['categoria_id' => $categoria->id]);
    }

    public function test_sin_permiso_de_catalogos_no_se_borra(): void
    {
        $categoria = Categoria::create(['nombre' => 'Otra', 'estado' => true]);
        $this->actuarComo('mesero');

        $this->deleteJson("/api/admin/categorias/{$categoria->id}")->assertForbidden();
        $this->assertDatabaseHas('categorias', ['id' => $categoria->id]);
    }

    // ── Unidades de medida ────────────────────────────────────

    public function test_unidad_de_medida_solo_se_borra_si_nada_la_usa(): void
    {
        $this->actuarComo('admin');
        $this->seed(UnidadesMedidaSeeder::class);
        $usada = UnidadesMedida::first();
        Producto::create([
            'nombre' => 'Harina', 'tipo' => 'bien', 'clase' => 'materia_prima',
            'precio_venta' => 0, 'costo_unitario' => 1, 'unidad_medida_id' => $usada->id,
        ]);
        $libre = UnidadesMedida::create(['nombre' => 'Bandeja', 'abreviatura' => 'band', 'tipo' => 'unidad', 'factor_base' => 1, 'estado' => true]);

        $tabla = collect($this->getJson('/api/admin/unidadmedida/tabla')->assertOk()->json())->keyBy('id');
        $this->assertTrue($tabla[$usada->id]['usada']);
        $this->assertFalse($tabla[$libre->id]['usada']);

        $this->deleteJson("/api/admin/unidadmedida/{$usada->id}")->assertStatus(422)->assertJsonPath('success', 0);
        $this->assertDatabaseHas('unidades_medida', ['id' => $usada->id]);

        $this->deleteJson("/api/admin/unidadmedida/{$libre->id}")->assertOk();
        $this->assertDatabaseMissing('unidades_medida', ['id' => $libre->id]);
        $this->assertDatabaseHas('auditorias', ['accion' => 'borrar_unidad']);

        $this->actuarComo('mesero');
        $this->deleteJson("/api/admin/unidadmedida/{$usada->id}")->assertForbidden();
    }
}
