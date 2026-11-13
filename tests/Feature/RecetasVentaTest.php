<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\ConfiguracionNegocio;
use App\Models\Inventario;
use App\Models\MetodoPago;
use App\Models\MovimientoCaja;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\TurnoCaja;
use App\Models\UnidadesMedida;
use App\Models\Venta;
use App\Services\CajaService;
use Database\Seeders\CajaSeeder;
use Database\Seeders\UnidadesMedidaSeeder;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Recetas que se descuentan al vender (limonada, platos, servicios), consumo interno
 * a $0.00, bajas manuales autorizadas y lista de compras del cierre de turno.
 */
class RecetasVentaTest extends TestCase
{
    private Caja $caja;
    private TurnoCaja $turno;
    private array $u;
    private Producto $limon;   // unidades, mínimo 20
    private Producto $azucar;  // gramos
    private Producto $aceite;  // se lleva en mililitros y se compra por galón
    private Producto $huevo;   // unidades
    private Producto $limonada;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CajaSeeder::class, UnidadesMedidaSeeder::class]);
        $this->u = UnidadesMedida::pluck('id', 'nombre')->all();
        $this->caja = Caja::first();
        $this->turno = CajaService::abrir($this->caja->id, 20, $this->crearUsuario('cajero')->id);

        $this->limon = $this->insumo('Limón', 'Unidad', 0.05, 30, 20);
        $this->azucar = $this->insumo('Azúcar', 'Gramo', 0.002, 5000, 1000, ['permite_fraccion' => true]);
        $this->aceite = $this->insumo('Aceite', 'Mililitro', 0.0032, 4 * 3785.4118, 500, ['permite_fraccion' => true]);
        $this->huevo = $this->insumo('Huevo', 'Unidad', 0.15, 60, 0);
        $this->limonada = $this->plato('Limonada', 1.00);
    }

    private function insumo(string $nombre, string $unidad, float $costo, float $stock, float $minimo, array $extra = []): Producto
    {
        $p = Producto::create(array_merge([
            'nombre' => $nombre, 'tipo' => 'bien', 'clase' => 'materia_prima', 'se_vende' => false,
            'controla_stock' => true, 'costo_unitario' => $costo, 'unidad_medida_id' => $this->u[$unidad],
        ], $extra));
        Inventario::create(['producto_id' => $p->id, 'stock_actual' => $stock, 'stock_minimo' => $minimo]);
        return $p;
    }

    // Lo que se vende sin existencia propia: sale de su receta
    private function plato(string $nombre, float $precio, array $extra = []): Producto
    {
        return Producto::create(array_merge([
            'nombre' => $nombre, 'tipo' => 'servicio', 'clase' => 'producto_terminado', 'se_vende' => true,
            'controla_stock' => false, 'precio_venta' => $precio, 'unidad_medida_id' => $this->u['Unidad'],
        ], $extra));
    }

    private function receta(Producto $producto, array $ingredientes, float $rendimiento = 1)
    {
        return $this->postJson('/api/produccion/recetas', [
            'producto_id' => $producto->id, 'rendimiento' => $rendimiento,
            'ingredientes' => array_map(fn ($i) => [
                'producto_id' => $i[0]->id, 'unidad_medida_id' => $this->u[$i[1]], 'cantidad' => $i[2],
            ], $ingredientes),
        ]);
    }

    private function vender(array $items, ?float $efectivo = null)
    {
        return $this->postJson('/api/pos/ventas', [
            'uuid'    => (string) Str::uuid(),
            'caja_id' => $this->caja->id,
            'items'   => array_map(fn ($i) => ['producto_id' => $i[0]->id, 'cantidad' => $i[1]], $items),
            'pagos'   => $efectivo === null ? [] : [['metodo_pago_id' => MetodoPago::where('nombre', 'Efectivo')->value('id'), 'monto' => $efectivo]],
        ]);
    }

    private function stock(Producto $p): float
    {
        return (float) Inventario::where('producto_id', $p->id)->value('stock_actual');
    }

    public function test_el_catalogo_dice_cuantos_platos_alcanzan_con_los_insumos(): void
    {
        $this->actuarComo('admin');
        // 4 limones y 30 g de azúcar: con 30 limones alcanzan 7 (el azúcar daría para 166)
        $this->receta($this->limonada, [[$this->limon, 'Unidad', 4], [$this->azucar, 'Gramo', 30]])->assertCreated();

        $alcanza = fn (string $ruta) => collect($this->getJson($ruta)->assertOk()->json('productos'))->firstWhere('id', $this->limonada->id)['alcanza'];
        $this->assertEquals(7, $alcanza('/api/pos/catalogo'));
        $this->assertEquals(7, $alcanza('/api/mesas/catalogo'));
        // Un insumo con «Permitir vender sin existencia» no limita: queda el azúcar (166)
        $this->limon->update(['permite_sin_existencia' => true]);
        $this->assertEquals(166, $alcanza('/api/pos/catalogo'));
        $this->limon->update(['permite_sin_existencia' => false]);

        // 2 limonadas en una mesa abierta apartan 8 limones: quedan 22, alcanzan 5
        $this->seed(\Database\Seeders\CajaSeeder::class);
        $mesa = \App\Models\Terminal::where('nombre', 'MESA 1')->first();
        $this->turno->update(['usuario_apertura_id' => auth('sanctum')->id()]);
        $orden = $this->postJson("/api/mesas/terminales/{$mesa->id}/abrir", [])->assertOk()->json('orden.id');
        $this->postJson("/api/mesas/ordenes/{$orden}/items", ['items' => [['producto_id' => $this->limonada->id, 'cantidad' => 2]]])->assertOk();
        // En mesas se ve lo que hay (al pasarse se avisa); la venta rápida sí resta lo apartado
        $this->assertEquals(7, $alcanza('/api/mesas/catalogo'));
        $this->assertEquals(5, $alcanza('/api/pos/catalogo'));

        // Sin limones no alcanza ninguna; un plato sin receta no dice nada
        Inventario::where('producto_id', $this->limon->id)->update(['stock_actual' => 3]);
        $this->assertEquals(0, $alcanza('/api/pos/catalogo'));
        $otro = $this->plato('Agua', 0.50);
        $this->assertNull(collect($this->getJson('/api/pos/catalogo')->json('productos'))->firstWhere('id', $otro->id)['alcanza']);
    }

    public function test_vender_con_receta_descuenta_los_insumos_y_costea_la_venta(): void
    {
        $this->actuarComo('admin');
        // 4 limones y 30 g de azúcar por limonada
        $this->receta($this->limonada, [[$this->limon, 'Unidad', 4], [$this->azucar, 'Gramo', 30]])
            ->assertCreated()->assertJsonPath('receta.uso', 'venta');

        $this->vender([[$this->limonada, 3]], 3)->assertCreated();

        $this->assertEquals(18, $this->stock($this->limon));
        $this->assertEquals(4910, $this->stock($this->azucar));
        // 12 x 0.05 + 90 x 0.002 = 0.78
        $this->assertEquals(0.78, (float) Venta::first()->costo_total);
        $this->assertDatabaseHas('movimientos_inventario', [
            'producto_id' => $this->limon->id, 'tipo' => 'venta', 'cantidad' => 12, 'motivo' => 'Venta 000001: Limonada',
        ]);

        // Una receta de venta no se produce
        $this->postJson('/api/produccion', ['uuid' => (string) Str::uuid(), 'receta_id' => \App\Models\Receta::first()->id, 'cantidad_producida' => 1])
            ->assertStatus(422)->assertJsonPath('message', 'La receta de Limonada se descuenta al vender: no se produce');
    }

    public function test_venta_rapida_con_extras_e_ingredientes_cambiados(): void
    {
        $this->actuarComo('admin');
        $desayuno = $this->plato('Desayuno', 2.50);
        $this->receta($desayuno, [[$this->huevo, 'Unidad', 2], [$this->aceite, 'Mililitro', 2]])->assertCreated();
        $this->postJson('/api/mesas/config/modificadores', ['nombre' => 'Endulzante', 'productos' => [$desayuno->id], 'opciones' => [
            ['nombre' => 'Azúcar extra', 'precio' => 0.25, 'producto_id' => $this->azucar->id, 'cantidad' => 10, 'unidad_medida_id' => $this->u['Gramo']],
            ['nombre' => 'Sin azúcar'],
        ]])->assertCreated();
        $azucarExtra = \App\Models\Modificador::where('nombre', 'Azúcar extra')->value('id');

        // El catálogo del POS trae los extras y la receta ajustable
        $this->getJson('/api/pos/catalogo')
            ->assertJsonFragment(['nombre' => 'Endulzante'])
            ->assertJsonFragment(['producto_id' => $this->huevo->id, 'nombre' => 'Huevo', 'base' => 2]);

        $venta = $this->postJson('/api/pos/ventas', [
            'uuid' => (string) Str::uuid(), 'caja_id' => $this->caja->id,
            'items' => [
                // 2 con azúcar extra y un huevo de más cada uno
                ['producto_id' => $desayuno->id, 'cantidad' => 2, 'modificadores' => [$azucarExtra],
                    'ajustes' => [['producto_id' => $this->huevo->id, 'cantidad' => 3]], 'notas' => 'bien cocidos'],
                // 1 sin huevo
                ['producto_id' => $desayuno->id, 'cantidad' => 1, 'ajustes' => [['producto_id' => $this->huevo->id, 'cantidad' => 0]]],
            ],
            'pagos' => [['metodo_pago_id' => MetodoPago::where('nombre', 'Efectivo')->value('id'), 'monto' => 10]],
        ])->assertCreated()->assertJsonPath('venta.total', 8);   // 2 x 2.75 + 2.50

        $detalles = Venta::find($venta->json('venta.id'))->detalles()->orderBy('id')->get();
        $this->assertSame('Desayuno (Azúcar extra, Huevo: 3 (lleva 2)) · bien cocidos', $detalles[0]->nombre_producto);
        $this->assertEquals(2.75, (float) $detalles[0]->precio_unitario);
        $this->assertSame('Desayuno (sin Huevo)', $detalles[1]->nombre_producto);
        $this->assertSame(['Azúcar extra', 'Huevo: 3 (lleva 2)', 'bien cocidos'], $detalles[0]->extras);

        // En la comanda el producto va solo y cada extra abajo con «*», un renglón por extra
        $transporte = new class implements \App\Support\Impresion\Transporte {
            public array $enviados = [];
            public function enviar(\App\Models\Impresora $impresora, string $bytes): void
            {
                $this->enviados[] = mb_convert_encoding($bytes, 'UTF-8', 'CP850');
            }
        };
        $this->app->instance(\App\Support\Impresion\TransporteRed::class, $transporte);
        // El desayuno se prepara en Cocina (sin área se entregaría directo y no saldría comanda)
        $desayunos = \App\Models\Categoria::create(['nombre' => 'Desayunos', 'estado' => true,
            'area_preparacion_id' => \App\Models\AreaPreparacion::where('nombre', 'Cocina')->value('id')]);
        $desayuno->categorias()->attach($desayunos->id);
        \App\Models\Impresora::create(['alias' => 'Caja', 'conexion' => 'red', 'ip_impresora' => '192.168.1.50', 'puerto' => 9100,
            'tipo_impresion' => 'escpos', 'ancho_caracteres' => 48, 'uso' => 'ambos', 'activo' => true, 'predeterminada' => true]);
        $this->postJson('/api/impresion/ventas/' . $venta->json('venta.id'), ['comandas' => true])->assertOk();
        $comanda = end($transporte->enviados);
        $this->assertStringContainsString("2       Desayuno\n", $comanda);
        $this->assertStringContainsString("        * Azúcar extra\n        * Huevo: 3 (lleva 2)\n        * bien cocidos\n", $comanda);
        $this->assertStringContainsString("        * sin Huevo\n", $comanda);
        $this->assertStringNotContainsString('Desayuno (', $comanda);

        // Huevos: 2 x 3 + 0 = 6; azúcar del extra: 2 x 10 g; aceite normal: 3 x 2 ml
        $this->assertEquals(54, $this->stock($this->huevo));
        $this->assertEquals(4980, $this->stock($this->azucar));
        $this->assertEqualsWithDelta(4 * 3785.4118 - 6, $this->stock($this->aceite), 0.0001);

        // Un extra que no es del producto se rechaza
        $this->postJson('/api/pos/ventas', [
            'uuid' => (string) Str::uuid(), 'caja_id' => $this->caja->id,
            'items' => [['producto_id' => $this->limonada->id, 'cantidad' => 1, 'modificadores' => [$azucarExtra]]],
            'pagos' => [],
        ])->assertStatus(422);
    }

    public function test_extra_con_cantidad_maxima_cobra_y_descuenta_por_cada_uno(): void
    {
        $this->actuarComo('admin');
        $desayuno = $this->plato('Desayuno típico', 3.00);
        $this->postJson('/api/mesas/config/modificadores', ['nombre' => 'Extras', 'multiple' => true, 'productos' => [$desayuno->id], 'opciones' => [
            ['nombre' => 'Huevo extra', 'precio' => 0.50, 'maximo' => 3, 'producto_id' => $this->huevo->id, 'cantidad' => 1, 'unidad_medida_id' => $this->u['Unidad']],
            ['nombre' => 'Crema'],
        ]])->assertCreated();
        $huevoExtra = \App\Models\Modificador::where('nombre', 'Huevo extra')->value('id');
        $crema = \App\Models\Modificador::where('nombre', 'Crema')->value('id');

        $this->getJson('/api/pos/catalogo')->assertJsonFragment(['id' => $huevoExtra, 'nombre' => 'Huevo extra', 'precio' => 0.5, 'maximo' => 3, 'agotado' => false]);
        $this->getJson('/api/mesas/config/modificadores')->assertJsonFragment(['nombre' => 'Huevo extra', 'maximo' => 3]);

        $item = fn (array $mods, int $cantidad = 1) => ['producto_id' => $desayuno->id, 'cantidad' => $cantidad, 'modificadores' => $mods];
        $venta = $this->postJson('/api/pos/ventas', [
            'uuid' => (string) Str::uuid(), 'caja_id' => $this->caja->id,
            // 2 desayunos, cada uno con 2 huevos extra y crema
            'items' => [$item([$huevoExtra, $huevoExtra, $crema], 2)],
            'pagos' => [['metodo_pago_id' => MetodoPago::where('nombre', 'Efectivo')->value('id'), 'monto' => 10]],
        ])->assertCreated()->assertJsonPath('venta.total', 8);   // 2 x (3.00 + 2 x 0.50)

        $detalle = Venta::find($venta->json('venta.id'))->detalles()->first();
        $this->assertSame('Desayuno típico (Huevo extra ×2, Crema)', $detalle->nombre_producto);
        $this->assertSame(['Huevo extra ×2', 'Crema'], $detalle->extras);
        $this->assertEquals(56, $this->stock($this->huevo)); // 60 - 2 desayunos x 2 huevos

        // Más del máximo se rechaza; una opción sin máximo se pide una sola vez
        $sinPagar = fn (array $mods) => $this->postJson('/api/pos/ventas', ['uuid' => (string) Str::uuid(), 'caja_id' => $this->caja->id,
            'items' => [$item($mods)], 'pagos' => []]);
        $sinPagar(array_fill(0, 4, $huevoExtra))->assertStatus(422)->assertJsonFragment(['message' => '«Huevo extra»: máximo 3 por Desayuno típico']);
        $sinPagar([$crema, $crema])->assertStatus(422);

        // En una mesa también: el precio de la línea lleva los 3 huevos
        $terminal = \App\Models\Terminal::where('nombre', 'MESA 1')->first();
        $orden = $this->postJson("/api/mesas/terminales/{$terminal->id}/abrir", [])->assertOk()->json('orden');
        $this->postJson("/api/mesas/ordenes/{$orden['id']}/items", ['items' => [$item(array_fill(0, 3, $huevoExtra))]])->assertOk()
            ->assertJsonFragment(['opcion' => 'Huevo extra', 'cantidad' => 3])
            ->assertJsonFragment(['precio_unitario' => 4.5]);
    }

    public function test_grupo_con_minimo_y_maximo_cuenta_las_unidades_en_total(): void
    {
        $this->actuarComo('admin');
        $chocolate = $this->plato('Chocolate con leche', 1.75);
        $base = ['nombre' => 'Tipo de leche', 'productos' => [$chocolate->id], 'opciones' => [
            ['nombre' => 'Leche entera'], ['nombre' => 'Deslactosada'], ['nombre' => 'Porción de leche', 'precio' => 0.35, 'maximo' => 2],
        ]];

        // El mínimo no puede pasar del máximo ni de lo que las opciones permiten
        $this->postJson('/api/mesas/config/modificadores', [...$base, 'minimo' => 3, 'maximo' => 2])->assertStatus(422);
        $this->postJson('/api/mesas/config/modificadores', [...$base, 'minimo' => 5])->assertStatus(422);
        $this->postJson('/api/mesas/config/modificadores', [...$base, 'minimo' => 1, 'maximo' => 2])->assertCreated();
        $grupo = \App\Models\GrupoModificador::where('nombre', 'Tipo de leche')->first();
        $this->assertTrue($grupo->obligatorio); // las versiones anteriores lo siguen viendo obligatorio
        $this->getJson('/api/pos/catalogo')->assertJsonFragment(['nombre' => 'Tipo de leche', 'minimo' => 1, 'maximo' => 2]);

        $id = fn (string $n) => \App\Models\Modificador::where('nombre', $n)->value('id');
        $vender = fn (array $mods) => $this->postJson('/api/pos/ventas', ['uuid' => (string) Str::uuid(), 'caja_id' => $this->caja->id,
            'items' => [['producto_id' => $chocolate->id, 'cantidad' => 1, 'modificadores' => $mods]],
            'pagos' => [['metodo_pago_id' => MetodoPago::where('nombre', 'Efectivo')->value('id'), 'monto' => 5]]]);

        // En la mesa sin elegir nada no deja agregar
        $terminal = \App\Models\Terminal::where('nombre', 'MESA 1')->first();
        $orden = $this->postJson("/api/mesas/terminales/{$terminal->id}/abrir", [])->assertOk()->json('orden');
        $this->postJson("/api/mesas/ordenes/{$orden['id']}/items", ['items' => [['producto_id' => $chocolate->id, 'cantidad' => 1, 'modificadores' => []]]])
            ->assertStatus(422)->assertJsonFragment(['message' => 'Elige «Tipo de leche» para Chocolate con leche']);
        // Entera + 2 porciones = 3: se pasa del máximo aunque cada opción esté dentro del suyo
        $vender([$id('Leche entera'), $id('Porción de leche'), $id('Porción de leche')])->assertStatus(422)
            ->assertJsonFragment(['message' => '«Tipo de leche»: elige de 1 a 2 para Chocolate con leche (llevas 3)']);
        $vender([$id('Leche entera'), $id('Porción de leche')])->assertCreated()->assertJsonPath('venta.total', 2.1);

        // Una versión anterior que solo manda «obligatorio» no borra el máximo
        $this->putJson("/api/mesas/config/modificadores/{$grupo->id}", [...$base, 'obligatorio' => true])->assertOk();
        $this->assertSame([1, 2], [$grupo->fresh()->minimo, $grupo->fresh()->maximo]);
    }

    public function test_cada_producto_tiene_sus_propios_modificadores_en_bloques(): void
    {
        $this->actuarComo('admin');
        $chocolate = $this->plato('Chocolate caliente', 1.75);
        $cafe = $this->plato('Café con leche', 1.50);
        $bloques = [
            ['nombre' => 'Tipo de leche', 'minimo' => 1, 'maximo' => 1, 'opciones' => [['nombre' => 'Entera'], ['nombre' => 'Deslactosada', 'precio' => 0.25]]],
            ['nombre' => 'Azúcar', 'maximo' => 1, 'opciones' => [['nombre' => 'Sin azúcar'], ['nombre' => 'Normal'], ['nombre' => 'Extra azúcar']]],
            ['nombre' => 'Extras', 'opciones' => [['nombre' => 'Porción de leche', 'precio' => 0.35]]],
        ];
        $url = fn (Producto $p) => "/api/mesas/config/productos/{$p->id}/modificadores";

        $this->getJson($url($chocolate))->assertOk()->assertJsonPath('conjunto', null);
        $this->putJson($url($chocolate), ['bloques' => $bloques])->assertOk();
        // El café recibe una copia: los mismos bloques, pero suyos
        $this->putJson($url($cafe), ['bloques' => $bloques])->assertOk();

        $config = $this->getJson('/api/mesas/config/modificadores')->assertOk();
        $this->assertSame(['Café con leche', 'Chocolate caliente'], collect($config->json('conjuntos'))->pluck('producto')->all());
        $suyo = $this->getJson($url($chocolate))->assertJsonPath('conjunto.producto', 'Chocolate caliente')
            ->assertJsonPath('conjunto.bloques.0.nombre', 'Tipo de leche')->json('conjunto');

        // La venta ve los tres bloques en orden y aplica el mínimo del primero
        $ids = collect($suyo['bloques'])->pluck('id')->all();
        $this->assertSame($ids, collect($this->getJson('/api/pos/catalogo')->json('productos'))->firstWhere('id', $chocolate->id)['grupos']);
        $opcion = fn (string $n) => collect($suyo['bloques'])->flatMap(fn ($b) => $b['opciones'])->firstWhere('nombre', $n)['id'];
        $vender = fn (array $mods) => $this->postJson('/api/pos/ventas', ['uuid' => (string) Str::uuid(), 'caja_id' => $this->caja->id,
            'items' => [['producto_id' => $chocolate->id, 'cantidad' => 1, 'modificadores' => $mods]],
            'pagos' => [['metodo_pago_id' => MetodoPago::where('nombre', 'Efectivo')->value('id'), 'monto' => 5]]]);
        $vender([$opcion('Normal')])->assertStatus(422);
        $vender([$opcion('Deslactosada'), $opcion('Normal'), $opcion('Porción de leche')])->assertCreated()->assertJsonPath('venta.total', 2.35);

        // Quitar una opción y un bloque al chocolate no toca al café
        $editados = collect($suyo['bloques'])->take(2)->map(fn ($b) => [
            'id' => $b['id'], 'nombre' => $b['nombre'], 'minimo' => $b['minimo'], 'maximo' => $b['maximo'],
            'opciones' => collect($b['opciones'])->reject(fn ($o) => $o['nombre'] === 'Extra azúcar')
                ->map(fn ($o) => ['id' => $o['id'], 'nombre' => $o['nombre'], 'precio' => $o['precio']])->values()->all(),
        ])->all();
        $this->putJson($url($chocolate), ['bloques' => $editados])->assertOk();
        $this->assertSame(array_slice($ids, 0, 2), $chocolate->gruposModificadores()->pluck('grupos_modificadores.id')->all());
        $this->assertCount(3, $cafe->gruposModificadores()->get());
        $this->getJson($url($cafe))->assertJsonPath('conjunto.bloques.1.opciones.2.nombre', 'Extra azúcar');

        // Quitar los modificadores de un producto
        $this->deleteJson($url($chocolate))->assertOk();
        $this->assertSame([], $chocolate->gruposModificadores()->pluck('grupos_modificadores.id')->all());
        $this->getJson($url($chocolate))->assertJsonPath('conjunto', null);
        $this->assertCount(3, $cafe->gruposModificadores()->get());

        $this->actuarComo('mesero');
        $this->putJson($url($cafe), ['bloques' => $bloques])->assertForbidden();
    }

    public function test_ingrediente_comprado_por_libra_se_ajusta_en_gramos(): void
    {
        $this->actuarComo('admin');
        $azucarLb = $this->insumo('Azúcar morena', 'Libra', 0.60, 10, 0, ['permite_fraccion' => true]);
        $cafe = $this->plato('Café', 1.50);
        $this->receta($cafe, [[$azucarLb, 'Gramo', 10]])->assertCreated();

        // Se muestra como se mide al preparar: 10 g, no 0.022 lb
        $this->getJson('/api/pos/catalogo')->assertJsonFragment(['producto_id' => $azucarLb->id, 'base' => 10, 'unidad' => 'g']);

        // Doble azúcar (20 g) en 2 cafés: salen 40 g = 0.0882 lb
        $this->postJson('/api/pos/ventas', [
            'uuid' => (string) Str::uuid(), 'caja_id' => $this->caja->id,
            'items' => [['producto_id' => $cafe->id, 'cantidad' => 2, 'ajustes' => [['producto_id' => $azucarLb->id, 'cantidad' => 20]]]],
            'pagos' => [['metodo_pago_id' => MetodoPago::where('nombre', 'Efectivo')->value('id'), 'monto' => 3]],
        ])->assertCreated();
        $this->assertEqualsWithDelta(10 - 40 / 453.59237, $this->stock($azucarLb), 0.0001);
        $this->assertSame('Café (Azúcar morena: 20 g (lleva 10 g))', Venta::first()->detalles()->first()->nombre_producto);
    }

    public function test_gasto_en_mililitros_de_un_insumo_comprado_por_galon(): void
    {
        $this->actuarComo('admin');
        $huevos = $this->plato('Huevos estrellados', 2.50);
        $this->receta($huevos, [[$this->aceite, 'Mililitro', 2], [$this->huevo, 'Unidad', 2]])->assertCreated();

        $this->vender([[$huevos, 10]], 25)->assertCreated();

        // 4 galones = 15,141.65 ml: salen 20 ml
        $this->assertEqualsWithDelta(15141.6472 - 20, $this->stock($this->aceite), 0.0001);
        $this->assertEquals(40, $this->stock($this->huevo));
    }

    public function test_servicio_con_insumos_y_anulacion_que_los_devuelve(): void
    {
        $this->actuarComo('admin');
        $frappe = $this->insumo('Frappé pequeño', 'Unidad', 4, 3, 0);
        $vela = $this->insumo('Vela de cumpleaños', 'Unidad', 0.25, 10, 0);
        $combo = $this->plato('Combo de cumpleaños', 3.00);
        $this->receta($combo, [[$frappe, 'Unidad', 1], [$vela, 'Unidad', 1]])->assertCreated();

        $id = $this->vender([[$combo, 1]], 3)->assertCreated()->assertJsonPath('venta.total', 3)->json('venta.id');
        $this->assertEquals(2, $this->stock($frappe));
        $this->assertEquals(9, $this->stock($vela));

        $this->postJson("/api/pos/ventas/{$id}/anular", ['motivo' => 'No se cantó'])->assertOk();
        $this->assertEquals(3, $this->stock($frappe));
        $this->assertEquals(10, $this->stock($vela));
    }

    public function test_insumo_insuficiente_rechaza_la_venta_sin_dejar_rastro(): void
    {
        ConfiguracionNegocio::actual()->update(['permitir_stock_negativo' => false]);
        $this->actuarComo('admin');
        $this->receta($this->limonada, [[$this->azucar, 'Gramo', 30], [$this->limon, 'Unidad', 4]])->assertCreated();

        $this->vender([[$this->limonada, 8]], 8)->assertStatus(422)
            ->assertJsonPath('message', 'Stock insuficiente para Limón: disponible 30, solicitado 32');

        $this->assertEquals(5000, $this->stock($this->azucar));
        $this->assertSame(0, Venta::count());
    }

    public function test_receta_de_venta_solo_lleva_insumos_con_inventario(): void
    {
        $this->actuarComo('admin');
        $hielo = $this->plato('Hielo', 0);

        $this->receta($this->limonada, [[$hielo, 'Unidad', 1]])->assertStatus(422)
            ->assertJsonPath('message', 'Hielo no lleva inventario: no se puede descontar al vender Limonada');

        // Tampoco en una receta de producción (el producto sí lleva inventario)
        $galleta = $this->plato('Galleta', 0.50, ['controla_stock' => true]);
        $this->receta($galleta, [[$hielo, 'Unidad', 1]], 10)->assertStatus(422)
            ->assertJsonPath('message', 'Hielo no lleva inventario: no se puede descontar al producir Galleta');
    }

    public function test_consumo_interno_no_cobra_y_solo_descuenta_inventario(): void
    {
        $this->actuarComo('admin');
        $cena = $this->postJson('/api/productos/registro', [
            'nombre' => 'Cena típica del personal', 'clase' => 'producto_terminado', 'se_vende' => false,
            'consumo_interno' => true, 'controla_stock' => false, 'permite_fraccion' => false,
            'costo_unitario' => 0, 'precio_venta' => 5, 'unidad_medida_id' => $this->u['Unidad'],
        ])->assertSuccessful();
        $cena = Producto::where('nombre', 'Cena típica del personal')->first();
        $this->assertTrue($cena->consumo_interno && $cena->se_vende);
        $this->assertEquals(0, (float) $cena->precio_venta);

        $this->receta($cena, [[$this->huevo, 'Unidad', 2]])->assertCreated();
        $this->receta($this->limonada, [[$this->limon, 'Unidad', 4]])->assertCreated();

        $this->vender([[$cena, 3]])->assertCreated()->assertJsonPath('venta.total', 0);
        $this->vender([[$this->limonada, 1]], 1)->assertCreated();

        $this->assertEquals(54, $this->stock($this->huevo));
        $this->assertSame(1, MovimientoCaja::where('tipo', 'venta')->count()); // solo la limonada
        $this->assertDatabaseHas('movimientos_inventario', ['producto_id' => $this->huevo->id, 'tipo' => 'consumo_interno', 'cantidad' => 6]);
        $this->assertDatabaseHas('ventas_detalle', ['producto_id' => $cena->id, 'consumo_interno' => true, 'subtotal' => 0]);

        $consumo = collect(CajaService::resumen($this->turno->fresh())['consumo'])->keyBy('producto');
        $this->assertEquals(6, $consumo['Huevo']['consumo_interno']);
        $this->assertEquals(0, $consumo['Huevo']['vendido']);
        $this->assertEquals(4, $consumo['Limón']['vendido']);
    }

    public function test_bajar_a_mano_un_insumo_de_receta_solo_lo_hace_el_administrador(): void
    {
        $this->actuarComo('admin');
        $this->receta($this->limonada, [[$this->aceite, 'Mililitro', 5]])->assertCreated();

        $admin = $this->crearUsuario('admin');
        $this->actuarComo('encargado_inventario');
        $ajuste = fn (Producto $p, float $conteo, array $extra = []) => $this->postJson('/api/inventario/entradas/registrar', array_merge([
            'fecha' => today()->toDateString(), 'tipo' => 'ajuste', 'descripcion' => 'Se acabó',
            'items' => [['producto_id' => $p->id, 'cantidad' => $conteo]],
        ], $extra));

        // "Ya se acabó el aceite": el aceite solo baja con los platos
        $ajuste($this->aceite, 0)->assertStatus(403)->assertJsonPath('permiso', 'inventario.autorizar_baja');
        $this->assertEqualsWithDelta(15141.6472, $this->stock($this->aceite), 0.0001);

        Sanctum::actingAs($admin, ['*'], 'sanctum');
        $ajuste($this->aceite, 0)->assertSuccessful();
        $this->assertEquals(0, $this->stock($this->aceite));
        $this->assertNotNull(\App\Models\EntradaInventario::latest('id')->first()->autorizado_por);

        // Subir un insumo o bajar algo que no está en recetas no lo pide
        $ajuste($this->aceite, 2)->assertSuccessful();
        $ajuste($this->huevo, 10)->assertSuccessful();
    }

    public function test_cierre_de_turno_deja_la_lista_de_compras_para_el_siguiente(): void
    {
        $this->actuarComo('admin');
        $this->receta($this->limonada, [[$this->limon, 'Unidad', 4]])->assertCreated();
        $this->vender([[$this->limonada, 3]], 3)->assertCreated(); // limón: 30 → 18, mínimo 20

        $cerrado = CajaService::cerrar($this->turno, null, 23, auth()->id());
        $porComprar = collect($cerrado->resumen['por_comprar'])->keyBy('producto');

        $this->assertTrue($porComprar->has('Limón'));
        $this->assertEquals(2, $porComprar['Limón']['faltante']);
        $this->assertFalse($porComprar->has('Azúcar')); // está arriba de su mínimo
        $this->assertEquals(12, collect($cerrado->resumen['consumo'])->firstWhere('producto', 'Limón')['vendido']);

        // Quien recibe la caja ve qué comprar antes de abrir
        $this->getJson("/api/caja/turno-actual?caja_id={$this->caja->id}")->assertOk()
            ->assertJsonPath('turno', null)
            ->assertJsonPath('turno_anterior.id', $cerrado->id)
            ->assertJsonPath('por_comprar.0.producto', 'Limón');
    }

    public function test_el_ticket_de_cierre_puede_omitir_por_comprar(): void
    {
        $this->actuarComo('admin');
        $this->receta($this->limonada, [[$this->limon, 'Unidad', 4]])->assertCreated();
        $this->vender([[$this->limonada, 3]], 3)->assertCreated(); // el limón queda bajo su mínimo
        $cerrado = CajaService::cerrar($this->turno, null, 23, auth()->id());
        $impresora = \App\Models\Impresora::create([
            'alias' => 'Mostrador', 'conexion' => 'red', 'ip_impresora' => '192.168.1.50', 'puerto' => 9100,
            'tipo_impresion' => 'escpos', 'ancho_caracteres' => 48, 'uso' => 'ticket', 'cash_drawer' => 'pin2', 'activo' => true,
        ]);
        $ticket = fn () => \App\Services\ImpresionService::cierre($cerrado->fresh(), $impresora)->bytes();

        // Por defecto el ticket imprime la lista
        $this->assertStringContainsString('POR COMPRAR', $ticket());
        $this->assertStringContainsString('hay 18 u', $ticket()); // Limón (el ticket va en la página de códigos de la impresora)

        // Apagado en Datos del negocio: el ticket ya no la imprime, pero la pantalla la sigue mostrando
        $this->postJson('/api/configuracion/negocio', [
            'nombre' => 'The Frozen Bar', 'moneda' => 'USD', 'simbolo_moneda' => '$', 'permitir_stock_negativo' => true,
            'ticket_por_comprar' => false,
        ])->assertOk()->assertJsonPath('data.ticket_por_comprar', false);

        $this->assertStringNotContainsString('POR COMPRAR', $ticket());
        $resumen = CajaService::resumen($cerrado->fresh());
        $this->assertFalse($resumen['ticket_por_comprar']);
        $this->assertSame('Limón', $resumen['por_comprar'][0]['producto']);
    }

    public function test_frijoles_crudos_en_libras_rinden_porciones_cocidas(): void
    {
        $this->actuarComo('admin');
        $crudo = $this->insumo('Frijol crudo', 'Libra', 0.90, 10, 0, ['permite_fraccion' => true]);
        $cocido = $this->insumo('Frijol cocido', 'Porción', 0, 0, 0, ['clase' => 'producto_terminado']);

        // 1 lb rinde 20 porciones
        $id = $this->receta($cocido, [[$crudo, 'Libra', 1]], 20)->assertCreated()
            ->assertJsonPath('receta.uso', 'produccion')->json('receta.id');

        $this->postJson('/api/produccion', [
            'uuid' => (string) Str::uuid(), 'receta_id' => $id, 'cantidad_planificada' => 80, 'cantidad_producida' => 80,
        ])->assertCreated();

        $this->assertEquals(6, $this->stock($crudo));
        $this->assertEquals(80, $this->stock($cocido));
    }
}
