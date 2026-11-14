<?php

namespace Tests\Feature;

use App\Models\ConfiguracionNegocio;
use App\Models\Inventario;
use App\Models\Notificacion;
use App\Models\Producto;
use App\Models\UnidadesMedida;
use App\Services\NotificacionService;
use Database\Seeders\UnidadesMedidaSeeder;
use Tests\TestCase;

class NotificacionesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(UnidadesMedidaSeeder::class);
    }

    private function crearProducto(float $stock, float $minimo): Producto
    {
        $producto = Producto::create([
            'nombre'           => 'Café molido',
            'tipo'             => 'bien',
            'clase'            => 'materia_prima',
            'costo_unitario'   => 1,
            'precio_venta'     => 0,
            'unidad_medida_id' => UnidadesMedida::where('nombre', 'Unidad')->firstOrFail()->id,
        ]);
        Inventario::create(['producto_id' => $producto->id, 'stock_actual' => $stock, 'stock_minimo' => $minimo]);

        return $producto;
    }

    private function ajustar(Producto $producto, float $cantidad)
    {
        return $this->postJson('/api/inventario/entradas/registrar', [
            'fecha'       => now()->toDateString(),
            'tipo'        => 'ajuste', // cantidad = existencia contada
            'descripcion' => 'Conteo',
            'items'       => [['producto_id' => $producto->id, 'cantidad' => $cantidad]],
        ]);
    }

    public function test_avisa_solo_cuando_el_producto_entra_en_alerta(): void
    {
        $admin = $this->actuarComo('admin');
        $producto = $this->crearProducto(stock: 20, minimo: 10);

        $this->ajustar($producto, 15)->assertOk();   // sigue arriba del mínimo
        $this->assertSame(0, Notificacion::count());

        $this->ajustar($producto, 8)->assertOk();    // entra en alerta
        $this->ajustar($producto, 5)->assertOk();    // sigue en alerta: no se repite

        $this->assertSame(1, Notificacion::where('usuario_id', $admin->id)->count());
        $this->assertSame('stock_bajo', Notificacion::first()->tipo);
    }

    public function test_marcar_todas_y_no_repetir_hasta_que_se_reponga(): void
    {
        $this->actuarComo('admin');
        $producto = $this->crearProducto(stock: 20, minimo: 10);
        $this->ajustar($producto, 8)->assertOk();

        $this->getJson('/api/notificaciones/resumen')->assertOk()->assertJsonPath('no_leidas', 1);
        $this->postJson('/api/notificaciones/leer-todas')->assertOk()->assertJsonPath('marcadas', 1);
        $this->getJson('/api/notificaciones/resumen')->assertJsonPath('no_leidas', 0);

        $this->ajustar($producto, 6)->assertOk();    // sigue bajo: nada nuevo
        $this->getJson('/api/notificaciones/resumen')->assertJsonPath('no_leidas', 0);

        $this->ajustar($producto, 30)->assertOk();   // se repone
        $this->ajustar($producto, 9)->assertOk();    // vuelve a bajar: aviso nuevo
        $this->getJson('/api/notificaciones/resumen')->assertJsonPath('no_leidas', 1);
    }

    public function test_subir_el_minimo_tambien_avisa(): void
    {
        $this->actuarComo('admin');
        $producto = $this->crearProducto(stock: 5, minimo: 2);

        $this->putJson("/api/inventario/stock-minimo/{$producto->id}", ['stock_minimo' => 10])->assertOk();

        $this->assertSame(1, Notificacion::count());
    }

    public function test_cada_usuario_ve_y_borra_solo_las_suyas(): void
    {
        $otro = $this->crearUsuario('admin');
        $yo = $this->actuarComo('admin');
        $producto = $this->crearProducto(stock: 20, minimo: 10);
        $this->ajustar($producto, 8)->assertOk();

        $ajena = Notificacion::where('usuario_id', $otro->id)->firstOrFail();
        $propia = Notificacion::where('usuario_id', $yo->id)->firstOrFail();

        $this->deleteJson("/api/notificaciones/{$ajena->id}")->assertNotFound();
        $this->deleteJson("/api/notificaciones/{$propia->id}")->assertOk();

        $this->assertDatabaseMissing('notificaciones', ['id' => $propia->id]);
        $this->assertDatabaseHas('notificaciones', ['id' => $ajena->id]);
    }

    public function test_vaciar_solo_las_leidas(): void
    {
        $yo = $this->actuarComo('admin');
        $this->crearProducto(stock: 1, minimo: 10);
        $this->crearProducto(stock: 2, minimo: 10);
        $this->assertSame(2, NotificacionService::sincronizarStock());

        $primera = Notificacion::where('usuario_id', $yo->id)->firstOrFail();
        $this->patchJson("/api/notificaciones/{$primera->id}/leer")->assertOk();

        $this->deleteJson('/api/notificaciones?solo=leidas')->assertOk()->assertJsonPath('borradas', 1);
        $this->getJson('/api/notificaciones')->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('notificaciones.0.sigue_en_alerta', true);
    }

    public function test_un_usuario_sin_permiso_de_inventario_no_recibe_avisos(): void
    {
        $mesero = $this->crearUsuario('editor');
        $this->actuarComo('admin');
        $producto = $this->crearProducto(stock: 20, minimo: 10);
        $this->ajustar($producto, 8)->assertOk();

        $this->assertSame(0, Notificacion::where('usuario_id', $mesero->id)->count());
    }

    public function test_producto_que_vende_sin_existencia_no_avisa(): void
    {
        $this->actuarComo('admin');
        $producto = $this->crearProducto(stock: 20, minimo: 10);
        $producto->update(['controla_stock' => true, 'permite_sin_existencia' => true]);

        $this->ajustar($producto, 8)->assertOk();                              // llega al mínimo
        NotificacionService::revisarStock($producto->fresh(), 8, -3, 10);      // queda en negativo
        $this->assertSame(0, NotificacionService::sincronizarStock());

        $this->assertSame(0, Notificacion::count());
    }

    public function test_marcar_vender_sin_existencia_borra_sus_avisos_pendientes(): void
    {
        $this->actuarComo('admin');
        $producto = $this->crearProducto(stock: 20, minimo: 10);
        $this->ajustar($producto, 8)->assertOk();
        $this->assertSame(1, Notificacion::count());

        $producto->update(['controla_stock' => true, 'permite_sin_existencia' => true]);
        NotificacionService::limpiarStock($producto);

        $this->assertSame(0, Notificacion::count());
    }

    public function test_con_stock_negativo_permitido_no_avisa_del_negativo(): void
    {
        $this->actuarComo('admin');
        ConfiguracionNegocio::actual()->update(['permitir_stock_negativo' => true]);
        $producto = $this->crearProducto(stock: 2, minimo: 0);

        NotificacionService::revisarStock($producto, 2, -1, 0);   // sin mínimo: queda en negativo
        $this->assertSame(0, Notificacion::count());

        $conMinimo = $this->crearProducto(stock: 20, minimo: 10);
        NotificacionService::revisarStock($conMinimo, 20, -1, 10); // sí avisa que se agotó
        $aviso = Notificacion::sole();
        $this->assertSame('stock_bajo', $aviso->tipo);
        $this->assertStringContainsString('se agotó', $aviso->titulo);
    }

    public function test_sin_stock_negativo_permitido_si_avisa_del_negativo(): void
    {
        $this->actuarComo('admin');
        ConfiguracionNegocio::actual()->update(['permitir_stock_negativo' => false]);
        $producto = $this->crearProducto(stock: 2, minimo: 0);

        NotificacionService::revisarStock($producto, 2, -1, 0);

        $this->assertSame('stock_negativo', Notificacion::sole()->tipo);
    }
}
