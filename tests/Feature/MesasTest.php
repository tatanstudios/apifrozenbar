<?php

namespace Tests\Feature;

use App\Models\AreaPreparacion;
use App\Models\Caja;
use App\Models\Categoria;
use App\Models\Comanda;
use App\Models\GrupoModificador;
use App\Models\Impresora;
use App\Models\Inventario;
use App\Models\MetodoPago;
use App\Models\Modificador;
use App\Models\Orden;
use App\Models\OrdenItem;
use App\Models\Producto;
use App\Models\Receta;
use App\Models\ConfiguracionNegocio;
use App\Models\Terminal;
use App\Models\TurnoCaja;
use App\Models\UnidadesMedida;
use App\Models\Venta;
use App\Services\CajaService;
use App\Support\Impresion\Transporte;
use App\Support\Impresion\TransporteRed;
use Database\Seeders\CajaSeeder;
use Database\Seeders\UnidadesMedidaSeeder;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MesasTest extends TestCase
{
    protected bool $turnoSigueAlUsuario = true;

    private Caja $caja;
    private Terminal $mesa1;
    private Terminal $mesa2;
    private Producto $frappe;    // barra, con leche (una sola) y saborizantes (varios)
    private Producto $sandwich;  // cocina
    private Producto $latteHelado;       // sin área: se entrega directo
    private array $op;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CajaSeeder::class, UnidadesMedidaSeeder::class]);

        $this->caja = Caja::first();
        $this->mesa1 = Terminal::where('nombre', 'MESA 1')->first();
        $this->mesa2 = Terminal::where('nombre', 'MESA 2')->first();
        $barra = AreaPreparacion::where('nombre', 'Barra')->value('id');
        $cocina = AreaPreparacion::where('nombre', 'Cocina')->value('id');
        $unidad = UnidadesMedida::where('nombre', 'Unidad')->value('id');

        $bebidas = Categoria::create(['nombre' => 'Bebidas', 'estado' => true, 'area_preparacion_id' => $barra]);
        $comida = Categoria::create(['nombre' => 'Comida', 'estado' => true, 'area_preparacion_id' => $cocina]);
        $heladas = Categoria::create(['nombre' => 'Bebidas heladas', 'estado' => true]);

        $crear = function (string $nombre, float $precio, Categoria $cat, bool $stock = false) use ($unidad) {
            $p = Producto::create(['nombre' => $nombre, 'tipo' => 'bien', 'clase' => 'producto_terminado', 'precio_venta' => $precio,
                'costo_unitario' => round($precio / 3, 2), 'controla_stock' => $stock, 'unidad_medida_id' => $unidad]);
            $p->categorias()->attach($cat->id);
            if ($stock) {
                Inventario::create(['producto_id' => $p->id, 'stock_actual' => 100]);
            }
            return $p;
        };
        $this->frappe = $crear('Frappuccino', 3.15, $bebidas);
        $this->sandwich = $crear('Sándwich', 4.00, $comida);
        $this->latteHelado = $crear('Latte helado', 0.15, $heladas, true);

        $leche = GrupoModificador::create(['nombre' => 'Leche']);
        $sabor = GrupoModificador::create(['nombre' => 'Saborizante']);
        $this->op = [
            // «Una sola»: elegir una leche quita la otra
            'entera'       => Modificador::create(['grupo_id' => $leche->id, 'nombre' => 'Entera', 'precio' => 0, 'exclusiva' => true])->id,
            'deslactosada' => Modificador::create(['grupo_id' => $leche->id, 'nombre' => 'Deslactosada', 'precio' => 0.25, 'exclusiva' => true])->id,
            'caramelo'     => Modificador::create(['grupo_id' => $sabor->id, 'nombre' => 'Caramelo', 'precio' => 0.50])->id,
            'vainilla'     => Modificador::create(['grupo_id' => $sabor->id, 'nombre' => 'Vainilla', 'precio' => 0.50])->id,
        ];
        $this->frappe->gruposModificadores()->attach([$leche->id => ['orden' => 1], $sabor->id => ['orden' => 2]]);

        CajaService::abrir($this->caja->id, 50, $this->crearUsuario('cajero')->id);
    }

    private function abrir(Terminal $t)
    {
        return $this->postJson("/api/mesas/terminales/{$t->id}/abrir", [])->assertOk()->json('orden');
    }

    private function agregar(int $orden, array $items, ?int $ticket = null)
    {
        return $this->postJson("/api/mesas/ordenes/{$orden}/items", ['ticket_id' => $ticket, 'items' => $items]);
    }

    private function frappe(array $mods = ['deslactosada', 'caramelo'], int $cantidad = 1): array
    {
        return ['producto_id' => $this->frappe->id, 'cantidad' => $cantidad, 'modificadores' => array_map(fn ($m) => $this->op[$m], $mods)];
    }

    public function test_productos_iguales_sin_enviar_se_suman_y_con_otros_extras_no(): void
    {
        $this->actuarComo('cajero');
        $orden = $this->abrir($this->mesa1)['id'];
        $this->agregar($orden, [['producto_id' => $this->latteHelado->id, 'cantidad' => 1]])->assertOk();
        $this->agregar($orden, [['producto_id' => $this->latteHelado->id, 'cantidad' => 2]])->assertOk();
        $this->agregar($orden, [$this->frappe(['deslactosada', 'caramelo'])])->assertOk();
        $this->agregar($orden, [$this->frappe(['caramelo', 'deslactosada'])])->assertOk(); // mismo, otro orden
        $this->agregar($orden, [$this->frappe(['entera'])])->assertOk();                    // otros extras
        $this->agregar($orden, [['producto_id' => $this->latteHelado->id, 'cantidad' => 1, 'notas' => 'tostado']])->assertOk();

        $items = OrdenItem::where('orden_id', $orden)->orderBy('id')->get();
        $this->assertCount(4, $items);
        $this->assertEqualsWithDelta(3, (float) $items[0]->cantidad, 0.0001);
        $this->assertEqualsWithDelta(2, (float) $items[1]->cantidad, 0.0001);

        // Lo ya enviado no se toca: el nuevo va en otra línea para la siguiente comanda
        $this->postJson("/api/mesas/ordenes/{$orden}/enviar")->assertOk();
        $this->agregar($orden, [['producto_id' => $this->latteHelado->id, 'cantidad' => 1]])->assertOk();
        $this->assertCount(5, OrdenItem::where('orden_id', $orden)->get());
    }

    public function test_el_mismo_producto_de_otro_mesero_va_en_su_propia_linea(): void
    {
        // Mesero 1 pide 3 cocas y se va; mesero 2 entra en la misma tablet y agrega 1 más
        $mesero1 = $this->actuarComo('mesero', ['nombre' => 'Mesero 1']);
        $orden = $this->abrir($this->mesa1)['id'];
        $this->agregar($orden, [['producto_id' => $this->latteHelado->id, 'cantidad' => 3]])->assertOk();
        $mesero2 = $this->actuarComo('mesero', ['nombre' => 'Mesero 2']);
        $this->agregar($orden, [['producto_id' => $this->latteHelado->id, 'cantidad' => 1]])->assertOk();
        $this->agregar($orden, [['producto_id' => $this->latteHelado->id, 'cantidad' => 1]])->assertOk();

        $items = OrdenItem::where('orden_id', $orden)->orderBy('id')->get();
        $this->assertCount(2, $items);
        $this->assertSame($mesero1->id, $items[0]->usuario_id);
        $this->assertEqualsWithDelta(3, (float) $items[0]->cantidad, 0.0001);
        $this->assertSame($mesero2->id, $items[1]->usuario_id);
        $this->assertEqualsWithDelta(2, (float) $items[1]->cantidad, 0.0001);
    }

    public function test_renombrar_la_cuenta(): void
    {
        $this->actuarComo('mesero');
        $orden = $this->abrir($this->mesa1);
        $ticket = $orden['tickets'][0]['id'];

        $this->putJson("/api/mesas/tickets/{$ticket}", ['nombre' => 'Juan'])->assertOk()
            ->assertJsonPath('orden.tickets.0.nombre', 'Juan');
        $this->putJson("/api/mesas/tickets/{$ticket}", ['nombre' => ''])->assertStatus(422);
    }

    public function test_borrar_una_cuenta_abierta_por_error(): void
    {
        $this->actuarComo('mesero');
        $orden = $this->abrir($this->mesa1);
        $t1 = $orden['tickets'][0]['id'];
        $borrar = fn (int $t) => $this->deleteJson("/api/mesas/tickets/{$t}");

        // La única cuenta abierta no se borra: para eso está «Liberar mesa»
        $borrar($t1)->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'única cuenta abierta'));

        // Abierta por error con algo sin enviar: se borra junto con eso
        $t2 = $this->postJson("/api/mesas/ordenes/{$orden['id']}/tickets")->assertCreated()->json('ticket_id');
        $this->agregar($orden['id'], [['producto_id' => $this->latteHelado->id, 'cantidad' => 1]], $t2)->assertOk();
        $borrar($t2)->assertOk()->assertJsonCount(1, 'orden.tickets');
        $this->assertSame(0, OrdenItem::where('ticket_id', $t2)->count());

        // Con algo ya enviado a cocina no: se cancela con motivo o se mueve
        $t3 = $this->postJson("/api/mesas/ordenes/{$orden['id']}/tickets")->json('ticket_id');
        $this->agregar($orden['id'], [['producto_id' => $this->latteHelado->id, 'cantidad' => 1]], $t3)->assertOk();
        $this->postJson("/api/mesas/ordenes/{$orden['id']}/enviar")->assertOk();
        $borrar($t3)->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'ya enviados a cocina'));

        // Pagada tampoco (la mesa sigue abierta por lo de Ticket 1)
        $this->agregar($orden['id'], [['producto_id' => $this->latteHelado->id, 'cantidad' => 1]], $t1)->assertOk();
        $this->cobrar($orden['id'], ['ticket_id' => $t3], 100)->assertCreated();
        $borrar($t3)->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'ya está pagada'));
    }

    public function test_cambiar_extras_y_validar_insumo_del_extra(): void
    {
        \App\Models\ConfiguracionNegocio::actual()->update(['permitir_stock_negativo' => false]);
        $ml = UnidadesMedida::where('nombre', 'Mililitro')->value('id');
        $leche = Producto::create(['nombre' => 'Leche entera', 'tipo' => 'bien', 'clase' => 'materia_prima', 'precio_venta' => 0,
            'costo_unitario' => 0.002, 'controla_stock' => true, 'unidad_medida_id' => $ml, 'permite_fraccion' => true]);
        Inventario::create(['producto_id' => $leche->id, 'stock_actual' => 300]);
        Modificador::whereKey($this->op['entera'])->update(['producto_id' => $leche->id, 'cantidad' => 200, 'unidad_medida_id' => $ml]);

        $this->actuarComo('mesero');
        $orden = $this->abrir($this->mesa1)['id'];
        $this->agregar($orden, [$this->frappe(['entera'])])->assertOk()->assertJsonPath('avisos', []);

        // Quedan 100 ml: otro con leche entera no alcanza; se agrega igual, con aviso (el cobro lo exige)
        $this->agregar($orden, [$this->frappe(['entera'])])->assertOk()
            ->assertJsonPath('avisos.0', 'Solo quedan 100 ml de Leche entera: no alcanza para Frappuccino. Se agregó, pero al cobrar se revisará.');
        // Mismo producto y extras del mismo mesero: una línea de 2; se deja en 1 (sin aviso)
        $item = OrdenItem::where('orden_id', $orden)->sole();
        $this->assertEqualsWithDelta(2, (float) $item->cantidad, 0.0001);
        $this->putJson("/api/mesas/items/{$item->id}", ['cantidad' => 1])->assertOk()->assertJsonPath('avisos', []);

        // El catálogo marca agotada la opción con la existencia real (no resta lo de cuentas abiertas)
        $agotada = fn () => collect($this->getJson('/api/mesas/catalogo')->json('grupos'))->flatMap(fn ($g) => $g['opciones'])->keyBy('id');
        $this->assertFalse($agotada()[$this->op['entera']]['agotado']);
        Inventario::where('producto_id', $leche->id)->update(['stock_actual' => 100]);
        $this->assertTrue($agotada()[$this->op['entera']]['agotado']);
        $this->assertFalse($agotada()[$this->op['deslactosada']]['agotado']);
        // Si la leche se vende sin existencia, la opción nunca sale agotada
        $leche->update(['permite_sin_existencia' => true]);
        $this->assertFalse($agotada()[$this->op['entera']]['agotado']);
        $leche->update(['permite_sin_existencia' => false]);
        Inventario::where('producto_id', $leche->id)->update(['stock_actual' => 300]);

        // Era deslactosada: se cambia el extra sin borrar la línea y el precio se recalcula
        $item = OrdenItem::where('orden_id', $orden)->first();
        $antes = (float) $item->precio_unitario;
        $this->putJson("/api/mesas/items/{$item->id}", ['modificadores' => [$this->op['deslactosada']]])->assertOk();
        $item->refresh();
        $this->assertSame('Deslactosada', $item->modificadores[0]['opcion']);
        $this->assertEqualsWithDelta($antes + 0.25, (float) $item->precio_unitario, 0.001);

        // Liberó la leche entera: alcanza para otro, sin aviso
        $this->agregar($orden, [$this->frappe(['entera'])])->assertOk()->assertJsonPath('avisos', []);
        // Y al cobrar se descuenta del inventario
        $this->cobrar($orden, [], 20)->assertCreated();
        $this->assertEqualsWithDelta(100, (float) Inventario::where('producto_id', $leche->id)->value('stock_actual'), 0.0001);

        // Lo ya enviado no se edita
        $orden = $this->abrir($this->mesa2)['id'];
        $this->agregar($orden, [$this->frappe(['deslactosada'])])->assertOk();
        $this->postJson("/api/mesas/ordenes/{$orden}/enviar")->assertOk();
        $enviado = OrdenItem::where('orden_id', $orden)->value('id');
        $this->putJson("/api/mesas/items/{$enviado}", ['modificadores' => [$this->op['entera']]])->assertStatus(422);
    }

    public function test_marcar_entregado_avisa_si_no_se_ha_enviado(): void
    {
        $this->actuarComo('cajero');
        $orden = $this->abrir($this->mesa1)['id'];
        // El sándwich pasa por cocina (el latte, sin área, queda entregado al enviar)
        $this->agregar($orden, [['producto_id' => $this->sandwich->id, 'cantidad' => 1]])->assertOk();
        $item = OrdenItem::where('orden_id', $orden)->value('id');

        $this->postJson("/api/mesas/ordenes/{$orden}/entregar", ['item_ids' => [$item]])->assertStatus(422)
            ->assertJsonPath('message', 'Esos productos aún no se envían: toca «Enviar» primero y luego márcalos entregados');

        $this->postJson("/api/mesas/ordenes/{$orden}/enviar")->assertOk();
        $this->postJson("/api/mesas/ordenes/{$orden}/entregar", ['item_ids' => [$item]])->assertOk()
            ->assertJsonPath('message', '1 producto(s) marcado(s) como entregado(s)');
        $this->assertSame('entregado', OrdenItem::find($item)->estado);
    }

    private function cobrar(int $orden, array $extra, float $monto = 100)
    {
        return $this->postJson("/api/mesas/ordenes/{$orden}/cobrar", array_merge([
            'uuid' => (string) Str::uuid(), 'caja_id' => $this->caja->id,
            'pagos' => [['metodo_pago_id' => MetodoPago::where('nombre', 'Efectivo')->value('id'), 'monto' => $monto]],
        ], $extra));
    }

    public function test_el_catalogo_de_mesas_trae_lo_disponible_y_el_mesero_puede_verlo(): void
    {
        Inventario::where('producto_id', $this->latteHelado->id)->update(['stock_minimo' => 100]);
        $this->actuarComo('mesero');
        $orden = $this->abrir($this->mesa1)['id'];
        $this->agregar($orden, [['producto_id' => $this->latteHelado->id, 'cantidad' => 4]])->assertOk();

        $latteHelado = collect($this->getJson('/api/mesas/catalogo')->assertOk()->json('productos'))->firstWhere('id', $this->latteHelado->id);
        $this->assertEquals(100, $latteHelado['stock']);
        $this->assertEquals(100, $latteHelado['disponible'], 'La tarjeta muestra la existencia real: lo de cuentas abiertas aún no sale');
        $this->assertTrue($latteHelado['bajo_minimo']);
        $this->assertSame('u', $latteHelado['unidad']);
        $this->assertNull(collect($this->getJson('/api/mesas/catalogo')->json('productos'))->firstWhere('id', $this->sandwich->id)['disponible']);

        // El permiso para ver la existencia en las tarjetas: el mesero lo trae, el admin lo puede quitar
        $this->assertTrue(\App\Models\Administrador::role('mesero')->first()->can('pos.ver_existencias'));
    }

    // ── Mapa y apertura ───────────────────────────────────────

    public function test_abrir_mesa_crea_orden_con_su_primera_cuenta(): void
    {
        $this->actuarComo('cajero');

        $orden = $this->abrir($this->mesa1);
        $this->assertSame('Ticket 1', $orden['tickets'][0]['nombre']);
        $this->assertSame($orden['id'], $this->abrir($this->mesa1)['id']); // abrir de nuevo: la misma orden

        $mapa = collect($this->getJson('/api/mesas/mapa')->assertOk()->json('terminales'))->keyBy('nombre');
        $this->assertNotNull($mapa['MESA 1']['orden']);
        $this->assertNull($mapa['MESA 2']['orden']);

        $caja = Terminal::where('tipo', 'caja')->first();
        $this->postJson("/api/mesas/terminales/{$caja->id}/abrir")->assertStatus(422);
    }

    // ── Modificadores ─────────────────────────────────────────

    public function test_sin_stock_negativo_avisa_al_agregar_y_el_cobro_lo_exige(): void
    {
        $this->actuarComo('admin');
        \App\Models\ConfiguracionNegocio::actual()->update(['permitir_stock_negativo' => false]);
        Inventario::where('producto_id', $this->latteHelado->id)->update(['stock_actual' => 5]);

        $orden1 = $this->abrir($this->mesa1)['id'];
        $this->agregar($orden1, [['producto_id' => $this->latteHelado->id, 'cantidad' => 3]])->assertOk()->assertJsonPath('avisos', []);

        // La tarjeta muestra la existencia real, aunque haya latte en otra cuenta abierta
        $this->getJson('/api/mesas/catalogo')->assertJsonPath('permitir_stock_negativo', false)
            ->assertJsonFragment(['nombre' => 'Latte helado', 'stock' => 5, 'disponible' => 5]);

        // Pasarse de lo que hay se agrega igual, con aviso (también en un mismo envío)
        $orden2 = $this->abrir($this->mesa2)['id'];
        $this->agregar($orden2, [['producto_id' => $this->latteHelado->id, 'cantidad' => 1], ['producto_id' => $this->latteHelado->id, 'cantidad' => 2]])
            ->assertOk()->assertJsonPath('avisos.0', 'No alcanzan las unidades de Latte helado: hay 5 y en cuentas abiertas ya van 6. Se agregó, pero al cobrar se revisará la existencia.');
        $item = OrdenItem::where('orden_id', $orden2)->value('id');

        // El botón «+» de la línea también avisa
        $this->putJson("/api/mesas/items/{$item}", ['cantidad' => 4])->assertOk()
            ->assertJsonPath('avisos.0', fn ($m) => str_starts_with($m, 'No alcanzan las unidades de Latte helado'));
        $this->putJson("/api/mesas/items/{$item}", ['cantidad' => 2])->assertOk()->assertJsonPath('avisos', []);

        // Sin existencia: también se agrega, con otro aviso
        Inventario::where('producto_id', $this->latteHelado->id)->update(['stock_actual' => 0]);
        $this->agregar($orden2, [['producto_id' => $this->latteHelado->id, 'cantidad' => 1]])->assertOk()
            ->assertJsonPath('avisos.0', 'No hay existencia de Latte helado: se agregó, pero no se podrá cobrar hasta que haya.');

        // El cobro es el que no deja vender lo que no hay
        $this->cobrar($orden1, [], 20)->assertStatus(422);
        $this->assertSame(0, Venta::count());

        // Si el negocio permite vender sin existencia, no hay avisos
        \App\Models\ConfiguracionNegocio::actual()->update(['permitir_stock_negativo' => true]);
        $this->agregar($orden2, [['producto_id' => $this->latteHelado->id, 'cantidad' => 1]])->assertOk()->assertJsonPath('avisos', []);
    }

    public function test_modificadores_suman_al_precio_y_se_validan(): void
    {
        $this->actuarComo('cajero');
        $orden = $this->abrir($this->mesa1)['id'];

        $item = $this->agregar($orden, [$this->frappe(['deslactosada', 'caramelo', 'vainilla'])])->assertOk()->json('orden.tickets.0.items.0');
        $this->assertEquals(4.40, $item['precio_unitario']);   // 3.15 + 0.25 + 0.50 + 0.50
        $this->assertSame('Frappuccino (Deslactosada, Caramelo, Vainilla)', $item['descripcion']);
        $this->assertSame('Barra', $item['area']);

        $this->agregar($orden, [$this->frappe(['entera', 'deslactosada'])])->assertStatus(422)
            ->assertJsonPath('message', '«Entera» no se combina con otras opciones de su grupo en Frappuccino');
        // Grupo obligatorio: hay que elegir al menos una leche
        GrupoModificador::where('nombre', 'Leche')->update(['obligatorio' => true]);
        $this->agregar($orden, [$this->frappe(['caramelo'])])->assertStatus(422)->assertJsonPath('message', 'Elige «Leche» para Frappuccino');
        GrupoModificador::where('nombre', 'Leche')->update(['obligatorio' => false]);

        // Una opción obligatoria va siempre, aunque la app no la mande; una apagada no se puede pedir
        Modificador::whereKey($this->op['vainilla'])->update(['obligatorio' => true]);
        $item = $this->agregar($orden, [$this->frappe(['entera', 'caramelo'])])->assertOk()->json('orden.tickets.0.items');
        $this->assertSame('Frappuccino (Entera, Caramelo, Vainilla)', end($item)['descripcion']);
        Modificador::whereKey($this->op['vainilla'])->update(['obligatorio' => false, 'activo' => false]);
        $this->agregar($orden, [$this->frappe(['entera', 'vainilla'])])->assertStatus(422)
            ->assertJsonPath('message', 'Uno de los extras no corresponde a Frappuccino');
        $this->agregar($orden, [['producto_id' => $this->sandwich->id, 'cantidad' => 1, 'modificadores' => [$this->op['caramelo']]]])
            ->assertStatus(422)->assertJsonPath('message', 'Uno de los extras no corresponde a Sándwich');
    }

    // ── Comandas ──────────────────────────────────────────────

    public function test_enviar_crea_una_comanda_por_area_y_lo_sin_area_se_entrega_directo(): void
    {
        $this->actuarComo('cajero');
        $orden = $this->abrir($this->mesa1)['id'];
        $this->agregar($orden, [$this->frappe(), ['producto_id' => $this->sandwich->id, 'cantidad' => 1, 'notas' => 'Sin cebolla'],
            ['producto_id' => $this->latteHelado->id, 'cantidad' => 3]]);

        $res = $this->postJson("/api/mesas/ordenes/{$orden}/enviar")->assertOk()
            ->assertJsonPath('impresion.sin_impresora', true);
        $this->assertStringContainsString('enviada a', $res->json('message'));

        // Una comanda por área; el latte (sin área) no pasa por cocina: queda entregado sin comanda
        $comandas = Comanda::all();
        $this->assertCount(2, $comandas);
        $this->assertSame(1, $comandas->pluck('numero')->unique()->count());
        $this->assertNull($comandas->firstWhere('area_id', null));
        $latteHelado = OrdenItem::where('producto_id', $this->latteHelado->id)->first();
        $this->assertSame('entregado', $latteHelado->estado);
        $this->assertNull($latteHelado->comanda_id);
        $this->assertSame(0, $res->json('orden.totales.por_enviar'));

        $this->postJson("/api/mesas/ordenes/{$orden}/enviar")->assertStatus(422)->assertJsonPath('message', 'No hay productos nuevos para enviar');

        // Lo que se agrega después va en una comanda nueva
        $this->agregar($orden, [$this->frappe(['entera'])]);
        $this->postJson("/api/mesas/ordenes/{$orden}/enviar")->assertOk();
        $this->assertSame(2, Comanda::distinct()->count('numero'));
    }

    public function test_terminales_y_areas_solo_se_borran_si_nunca_se_usaron(): void
    {
        $this->actuarComo('admin');
        $nueva = Terminal::create(['nombre' => 'Mesa nueva', 'tipo' => 'mesa', 'zona' => 'Salón', 'capacidad' => 2, 'orden' => 99, 'activo' => true]);
        $vacia = AreaPreparacion::create(['nombre' => 'Postres', 'color' => '#7c3aed', 'activo' => true]);

        // MESA 1 recibe una orden enviada: la mesa y Barra/Cocina quedan usadas
        $orden = $this->abrir($this->mesa1)['id'];
        $this->agregar($orden, [$this->frappe(), ['producto_id' => $this->sandwich->id, 'cantidad' => 1]]);
        $this->postJson("/api/mesas/ordenes/{$orden}/enviar")->assertOk();

        $terminales = collect($this->getJson('/api/mesas/config/terminales')->assertOk()->json('terminales'))->keyBy('id');
        $this->assertTrue($terminales[$this->mesa1->id]['usada']);
        $this->assertFalse($terminales[$nueva->id]['usada']);
        $areas = collect($this->getJson('/api/mesas/config/areas')->assertOk()->json('areas'))->keyBy('nombre');
        $this->assertTrue($areas['Barra']['usada']);
        $this->assertFalse($areas['Postres']['usada']);

        // Usadas: no se borran
        $this->deleteJson("/api/mesas/config/terminales/{$this->mesa1->id}")->assertStatus(422);
        $this->deleteJson("/api/mesas/config/areas/{$areas['Barra']['id']}")->assertStatus(422);
        $this->assertDatabaseHas('terminales', ['id' => $this->mesa1->id]);

        // Sin usar pero con categorías: primero hay que reasignarlas
        Categoria::where('nombre', 'Bebidas heladas')->update(['area_preparacion_id' => $vacia->id]);
        $this->deleteJson("/api/mesas/config/areas/{$vacia->id}")->assertStatus(422)
            ->assertJsonPath('success', 0);
        Categoria::where('nombre', 'Bebidas heladas')->update(['area_preparacion_id' => null]);

        // Nunca usadas: se borran
        $this->deleteJson("/api/mesas/config/terminales/{$nueva->id}")->assertOk();
        $this->deleteJson("/api/mesas/config/areas/{$vacia->id}")->assertOk();
        $this->assertDatabaseMissing('terminales', ['id' => $nueva->id]);
        $this->assertDatabaseMissing('areas_preparacion', ['id' => $vacia->id]);
        $this->assertDatabaseHas('auditorias', ['accion' => 'borrar_terminal']);
        $this->assertDatabaseHas('auditorias', ['accion' => 'borrar_area']);

        // Un mesero no configura mesas
        $this->actuarComo('mesero');
        $this->deleteJson("/api/mesas/config/terminales/{$this->mesa2->id}")->assertForbidden();
    }

    public function test_cada_area_imprime_su_propio_ticket_aunque_haya_una_sola_impresora(): void
    {
        $transporte = new class implements Transporte {
            public array $enviados = [];
            public function enviar(Impresora $impresora, string $bytes): void
            {
                $this->enviados[] = ['impresora' => $impresora->alias, 'texto' => mb_convert_encoding($bytes, 'UTF-8', 'CP850')];
            }
        };
        $this->app->instance(TransporteRed::class, $transporte);
        $impresora = fn (string $alias, string $uso) => Impresora::create(['alias' => $alias, 'conexion' => 'red', 'ip_impresora' => '192.168.1.50',
            'puerto' => 9100, 'tipo_impresion' => 'escpos', 'ancho_caracteres' => 48, 'uso' => $uso, 'activo' => true]);
        $tablet = $impresora('Tablet', 'ticket'); // la única impresora: la de tickets, sin categorías

        $this->actuarComo('cajero');
        $orden = $this->abrir($this->mesa1)['id'];
        $this->agregar($orden, [$this->frappe(), ['producto_id' => $this->sandwich->id, 'cantidad' => 1, 'notas' => 'Sin cebolla'],
            ['producto_id' => $this->latteHelado->id, 'cantidad' => 3]]);

        $res = $this->postJson("/api/mesas/ordenes/{$orden}/enviar")->assertOk()
            ->assertJsonPath('impresion.impresas', 2)->assertJsonPath('impresion.sin_impresora', false);
        $this->assertCount(2, $res->json('comandas'));

        $tickets = collect($transporte->enviados)->keyBy(fn ($e) => str_contains($e['texto'], 'Sándwich') ? 'cocina'
            : (str_contains($e['texto'], 'Frappuccino') ? 'barra' : 'general'));
        $this->assertCount(2, $tickets); // el latte (sin área) se entrega directo: no imprime
        $this->assertStringContainsString('Sándwich', $tickets['cocina']['texto']);
        $this->assertStringContainsString('Sin cebolla', $tickets['cocina']['texto']);
        $this->assertStringNotContainsString('Frappuccino', $tickets['cocina']['texto']);
        $this->assertStringContainsString('Solicitud de producción', $tickets['barra']['texto']);
        $this->assertStringContainsString('Frappuccino', $tickets['barra']['texto']);
        $this->assertStringNotContainsString('Sándwich', $tickets['barra']['texto']);
        $this->assertStringNotContainsString('Latte helado', $tickets['cocina']['texto'] . $tickets['barra']['texto']);
        $this->assertStringContainsString('Mesa: ', $tickets['barra']['texto']);
        $this->assertStringContainsString('Ticket 1', $tickets['barra']['texto']);
        $this->assertStringContainsString('CANT    DESCRIPCIÓN', $tickets['barra']['texto']);
        $this->assertStringNotContainsString('Mesero:', $tickets['barra']['texto']); // igual al formato del negocio

        // Si el área tiene su impresora, va a esa
        $barra = $impresora('Barra', 'comanda');
        AreaPreparacion::where('nombre', 'Barra')->update(['impresora_id' => $barra->id]);
        $this->agregar($orden, [$this->frappe(['entera'])]);
        $this->postJson("/api/mesas/ordenes/{$orden}/enviar")->assertOk();
        $this->assertSame('Barra', end($transporte->enviados)['impresora']);
        $this->assertCount(3, $transporte->enviados);

        // Reimprimir desde la mesa devuelve la comanda para el navegador
        $this->postJson('/api/mesas/comandas/' . Comanda::first()->id . '/imprimir')->assertOk()
            ->assertJsonPath('impreso', true)->assertJsonStructure(['comanda' => ['area', 'items']]);
        $this->assertStringContainsString('COPIA', end($transporte->enviados)['texto']);
        $this->assertNotNull($tablet->id);
    }

    public function test_precuenta_se_imprime_en_la_termica(): void
    {
        $transporte = new class implements Transporte {
            public array $enviados = [];
            public function enviar(Impresora $impresora, string $bytes): void
            {
                $this->enviados[] = mb_convert_encoding($bytes, 'UTF-8', 'CP850');
            }
        };
        $this->app->instance(TransporteRed::class, $transporte);
        Impresora::create(['alias' => 'Tablet', 'conexion' => 'red', 'ip_impresora' => '192.168.1.50', 'puerto' => 9100,
            'tipo_impresion' => 'escpos', 'ancho_caracteres' => 48, 'uso' => 'ticket', 'activo' => true, 'predeterminada' => true]);

        $this->actuarComo('cajero');
        $orden = $this->abrir($this->mesa1)['id'];
        $this->agregar($orden, [$this->frappe(), ['producto_id' => $this->sandwich->id, 'cantidad' => 2]]);

        $this->postJson("/api/mesas/ordenes/{$orden}/precuenta")->assertOk()->assertJsonPath('impreso', true);
        $this->assertStringContainsString('PRECUENTA', $transporte->enviados[0]);
        $this->assertStringContainsString('MESA 1', $transporte->enviados[0]);
        // Cada extra en su renglón con «*», como en la comanda
        $this->assertStringContainsString("Frappuccino\n  * Deslactosada (+\$0.25)\n  * Caramelo (+\$0.50)\n", $transporte->enviados[0]);
        $this->assertStringNotContainsString('Frappuccino (', $transporte->enviados[0]);
        $this->assertStringContainsString('no es comprobante de pago', $transporte->enviados[0]);
    }

    public function test_la_comanda_se_marca_entregada_desde_la_mesa(): void
    {
        $this->actuarComo('cajero');
        $orden = $this->abrir($this->mesa1)['id'];
        $this->agregar($orden, [$this->frappe(), ['producto_id' => $this->sandwich->id, 'cantidad' => 1]]);
        $this->postJson("/api/mesas/ordenes/{$orden}/enviar");

        $cocina = Comanda::whereHas('area', fn ($q) => $q->where('nombre', 'Cocina'))->first();
        $this->postJson("/api/mesas/comandas/{$cocina->id}/entregar")->assertOk()->assertJsonPath('comanda.estado', 'entregada');
        $this->assertSame('entregado', OrdenItem::where('producto_id', $this->sandwich->id)->value('estado'));
        $this->assertSame('enviado', OrdenItem::where('producto_id', $this->frappe->id)->value('estado'));
        $this->postJson("/api/mesas/comandas/{$cocina->id}/entregar")->assertStatus(422);

        // Ya no hay pantalla de cocina
        $this->getJson('/api/cocina/comandas')->assertNotFound();
    }

    // ── Cuentas separadas y cobro ─────────────────────────────

    public function test_cuentas_separadas_mover_dividir_y_cobrar_por_cuenta(): void
    {
        $this->actuarComo('cajero');
        $orden = $this->abrir($this->mesa1);
        $ticket1 = $orden['tickets'][0]['id'];
        $this->agregar($orden['id'], [$this->frappe(cantidad: 2), ['producto_id' => $this->latteHelado->id, 'cantidad' => 4]]);

        $ticket2 = $this->postJson("/api/mesas/ordenes/{$orden['id']}/tickets", ['nombre' => 'Luz'])->assertCreated()->json('ticket_id');
        $frappe = OrdenItem::where('producto_id', $this->frappe->id)->first();
        $latteHelado = OrdenItem::where('producto_id', $this->latteHelado->id)->first();

        $this->postJson("/api/mesas/items/{$frappe->id}/dividir", ['cantidad' => 1, 'ticket_id' => $ticket2])->assertOk();
        $this->postJson("/api/mesas/ordenes/{$orden['id']}/mover", ['item_ids' => [$latteHelado->id], 'ticket_id' => $ticket2])->assertOk();

        // Ticket 1: un frappé (3.90). Luz: un frappé + 4 lattes helados (3.90 + 0.60)
        $tickets = collect($this->getJson("/api/mesas/terminales/{$this->mesa1->id}")->json('orden.tickets'))->keyBy('nombre');
        $this->assertEquals(3.90, $tickets['Ticket 1']['total']);
        $this->assertEquals(4.50, $tickets['Luz']['total']);

        $venta = $this->cobrar($orden['id'], ['ticket_id' => $ticket1], 5)->assertCreated()->json('venta');
        $this->assertEquals(3.90, $venta['total']);
        $this->assertSame('Frappuccino (Deslactosada, Caramelo)', $venta['detalles'][0]['nombre']);
        $this->assertSame($orden['id'], Venta::find($venta['id'])->orden_id);
        $this->assertSame('abierta', Orden::find($orden['id'])->estado);

        // Ya no se puede agregar a la cuenta pagada
        $this->agregar($orden['id'], [['producto_id' => $this->latteHelado->id, 'cantidad' => 1]], $ticket1)->assertStatus(422);

        $this->cobrar($orden['id'], ['ticket_id' => $ticket2], 10)->assertCreated()
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'MESA 1 queda libre'));

        $this->assertSame('cerrada', Orden::find($orden['id'])->estado);
        $this->assertEquals(96, (float) Inventario::where('producto_id', $this->latteHelado->id)->value('stock_actual'));
        $this->assertEquals(58.40, CajaService::resumen(TurnoCaja::first())['efectivo_esperado']);
    }

    public function test_cobrar_productos_elegidos_y_anular_reabre_la_mesa(): void
    {
        $this->actuarComo('admin');
        $orden = $this->abrir($this->mesa1)['id'];
        $this->agregar($orden, [['producto_id' => $this->sandwich->id, 'cantidad' => 1], ['producto_id' => $this->latteHelado->id, 'cantidad' => 2]]);
        $latteHelado = OrdenItem::where('producto_id', $this->latteHelado->id)->value('id');
        $sandwich = OrdenItem::where('producto_id', $this->sandwich->id)->value('id');

        $this->cobrar($orden, ['item_ids' => [$latteHelado]], 1)->assertCreated()->assertJsonPath('venta.total', 0.3);
        $this->cobrar($orden, ['item_ids' => [$latteHelado]])->assertStatus(422)->assertJsonPath('message', 'No hay productos por cobrar en esa cuenta');

        $venta = $this->cobrar($orden, ['item_ids' => [$sandwich]], 5)->json('venta');
        $this->assertSame('cerrada', Orden::find($orden)->estado);

        $this->postJson("/api/pos/ventas/{$venta['id']}/anular", ['motivo' => 'Se cobró a la mesa equivocada'])->assertOk();
        $this->assertSame('abierta', Orden::find($orden)->estado);
        $this->assertNull(OrdenItem::find($sandwich)->venta_id);
    }

    public function test_reintento_de_cobro_no_duplica(): void
    {
        $this->actuarComo('cajero');
        $orden = $this->abrir($this->mesa1)['id'];
        $this->agregar($orden, [['producto_id' => $this->sandwich->id, 'cantidad' => 1]]);
        $uuid = (string) Str::uuid();

        $this->cobrar($orden, ['uuid' => $uuid], 5)->assertCreated();
        $this->cobrar($orden, ['uuid' => $uuid], 5)->assertOk();
        $this->assertSame(1, Venta::count());
    }

    // ── Quitar, cerrar y trasladar ────────────────────────────

    public function test_quitar_producto_enviado_solo_lo_hace_el_administrador(): void
    {
        $admin = $this->crearUsuario('admin', ['nombre' => 'Gerente']);
        $this->actuarComo('cajero');
        $orden = $this->abrir($this->mesa1)['id'];
        $this->agregar($orden, [['producto_id' => $this->latteHelado->id, 'cantidad' => 1], ['producto_id' => $this->sandwich->id, 'cantidad' => 1]]);
        $latteHelado = OrdenItem::where('producto_id', $this->latteHelado->id)->value('id');

        $this->postJson("/api/mesas/items/{$latteHelado}/quitar")->assertOk();          // pendiente: se borra
        $this->assertNull(OrdenItem::find($latteHelado));

        $this->postJson("/api/mesas/ordenes/{$orden}/enviar");
        $sandwich = OrdenItem::where('producto_id', $this->sandwich->id)->value('id');
        $this->postJson("/api/mesas/items/{$sandwich}/quitar", ['motivo' => 'El cliente cambió de opinión'])->assertStatus(403);
        Sanctum::actingAs($admin, ['*'], 'sanctum');
        $this->postJson("/api/mesas/items/{$sandwich}/quitar", ['motivo' => 'El cliente cambió de opinión'])->assertOk();

        $this->assertSame('cancelado', OrdenItem::find($sandwich)->estado);
        $this->assertSame('cancelada', Comanda::first()->estado);
        $this->assertDatabaseHas('auditorias', ['accion' => 'cancelar_item']);
    }

    public function test_liberar_mesa_con_pendientes_solo_lo_hace_el_administrador(): void
    {
        $admin = $this->crearUsuario('admin', ['nombre' => 'Gerente']);
        $this->actuarComo('cajero');
        $orden = $this->abrir($this->mesa1)['id'];
        $this->agregar($orden, [['producto_id' => $this->sandwich->id, 'cantidad' => 1]]);

        $this->postJson("/api/mesas/ordenes/{$orden}/cerrar")->assertStatus(422);
        $this->postJson("/api/mesas/ordenes/{$orden}/cerrar", ['motivo' => 'Se fueron sin consumir'])->assertStatus(403);
        Sanctum::actingAs($admin, ['*'], 'sanctum');
        $this->postJson("/api/mesas/ordenes/{$orden}/cerrar", ['motivo' => 'Se fueron sin consumir'])->assertOk();
        $this->assertSame('cancelada', Orden::find($orden)->estado);

        // Mesa vacía: se libera sin autorización
        $vacia = $this->abrir($this->mesa2)['id'];
        $this->postJson("/api/mesas/ordenes/{$vacia}/cerrar")->assertOk();
    }

    public function test_trasladar_a_mesa_libre(): void
    {
        $this->actuarComo('cajero');
        $orden = $this->abrir($this->mesa1)['id'];
        $this->abrir($this->mesa2);
        $mesa3 = Terminal::where('nombre', 'MESA 3')->first();

        $this->postJson("/api/mesas/ordenes/{$orden}/trasladar", ['terminal_id' => $this->mesa2->id])->assertStatus(422)
            ->assertJsonPath('message', 'MESA 2 está ocupada');
        $this->postJson("/api/mesas/ordenes/{$orden}/trasladar", ['terminal_id' => $mesa3->id])->assertOk()
            ->assertJsonPath('orden.terminal.nombre', 'MESA 3');
    }

    // ── Configuración y permisos ──────────────────────────────

    public function test_el_extra_con_insumo_lo_descuenta_al_cobrar_y_lo_devuelve_al_anular(): void
    {
        $this->actuarComo('admin');
        $litro = UnidadesMedida::where('nombre', 'Litro')->value('id');
        $jarabe = Producto::create(['nombre' => 'Jarabe de fresa', 'tipo' => 'bien', 'clase' => 'materia_prima', 'precio_venta' => 0,
            'costo_unitario' => 4, 'controla_stock' => true, 'unidad_medida_id' => $litro, 'permite_fraccion' => true]);
        Inventario::create(['producto_id' => $jarabe->id, 'stock_actual' => 2]);

        // El insumo debe llevar inventario y medirse en una unidad convertible
        $base = ['nombre' => 'Topping', 'multiple' => true, 'productos' => [$this->frappe->id]];
        $gramo = UnidadesMedida::where('nombre', 'Gramo')->value('id');
        $this->postJson('/api/mesas/config/modificadores', [...$base, 'opciones' => [
            ['nombre' => 'Fresa', 'precio' => 0.5, 'producto_id' => $jarabe->id, 'cantidad' => 30, 'unidad_medida_id' => $gramo],
        ]])->assertStatus(422);
        $this->postJson('/api/mesas/config/modificadores', [...$base, 'opciones' => [
            ['nombre' => 'Fresa', 'precio' => 0.5, 'producto_id' => $this->sandwich->id, 'cantidad' => 1, 'unidad_medida_id' => $gramo],
        ]])->assertStatus(422);

        $ml = UnidadesMedida::where('nombre', 'Mililitro')->value('id');
        $this->postJson('/api/mesas/config/modificadores', [...$base, 'opciones' => [
            ['nombre' => 'Fresa', 'precio' => 0.5, 'producto_id' => $jarabe->id, 'cantidad' => 30, 'unidad_medida_id' => $ml],
            ['nombre' => 'Sin topping'],
        ]])->assertCreated();
        $fresa = Modificador::where('nombre', 'Fresa')->value('id');
        $this->getJson('/api/mesas/config/modificadores')
            ->assertJsonFragment(['nombre' => 'Fresa', 'insumo' => 'Jarabe de fresa', 'cantidad' => 30, 'unidad' => 'ml']);

        $orden = $this->abrir($this->mesa1)['id'];
        $this->agregar($orden, [['producto_id' => $this->frappe->id, 'cantidad' => 2,
            'modificadores' => [$this->op['entera'], $fresa]]])->assertOk();
        $venta = $this->cobrar($orden, [], 10)->assertCreated()->json('venta');

        // 2 frappés x 30 ml = 0.06 l a $4 el litro: el costo de la línea sube $0.12 por frappé
        $this->assertEqualsWithDelta(1.94, (float) Inventario::where('producto_id', $jarabe->id)->value('stock_actual'), 0.0001);
        $detalle = Venta::find($venta['id'])->detalles()->first();
        $this->assertEqualsWithDelta(1.05 + 0.12, (float) $detalle->costo_unitario, 0.0001);

        $this->postJson("/api/pos/ventas/{$venta['id']}/anular", ['motivo' => 'Prueba'])->assertOk();
        $this->assertEqualsWithDelta(2, (float) Inventario::where('producto_id', $jarabe->id)->value('stock_actual'), 0.0001);
    }

    public function test_configurar_modificadores_areas_y_terminales(): void
    {
        $this->actuarComo('admin');

        $this->postJson('/api/mesas/config/modificadores', [
            'nombre' => 'Tamaño', 'obligatorio' => true,
            'opciones' => [['nombre' => 'Mediano', 'precio' => 0, 'obligatorio' => true, 'exclusiva' => true],
                ['nombre' => 'Grande', 'precio' => 0.75, 'exclusiva' => true], ['nombre' => 'Extra grande', 'activo' => false]],
            'productos' => [$this->frappe->id],
        ])->assertCreated();
        // Cada opción guarda sus reglas; la apagada se ve en la configuración pero no al vender
        $this->getJson('/api/mesas/config/modificadores')
            ->assertJsonFragment(['nombre' => 'Tamaño', 'obligatorio' => true])
            ->assertJsonFragment(['nombre' => 'Mediano', 'obligatorio' => true, 'exclusiva' => true])
            ->assertJsonFragment(['nombre' => 'Extra grande', 'activo' => false]);
        $this->getJson('/api/mesas/catalogo')->assertJsonMissing(['nombre' => 'Extra grande']);
        $catalogo = $this->getJson('/api/mesas/catalogo')->assertOk();
        $this->assertCount(3, collect($catalogo->json('productos'))->firstWhere('id', $this->frappe->id)['grupos']);

        $latteHelado = Categoria::where('nombre', 'Bebidas heladas')->first();
        $this->putJson('/api/mesas/config/categorias', ['asignaciones' => [['categoria_id' => $latteHelado->id, 'area_id' => AreaPreparacion::where('nombre', 'Cocina')->value('id')]]])->assertOk();
        // Sin área: se entrega directo (null es válido; la clave tiene que venir)
        $this->putJson('/api/mesas/config/categorias', ['asignaciones' => [['categoria_id' => $latteHelado->id, 'area_id' => null]]])->assertOk();
        $this->assertNull($latteHelado->fresh()->area_preparacion_id);
        $this->putJson('/api/mesas/config/categorias', ['asignaciones' => [['categoria_id' => $latteHelado->id]]])->assertStatus(422);
        $this->putJson('/api/mesas/config/categorias', ['asignaciones' => [['categoria_id' => $latteHelado->id, 'area_id' => AreaPreparacion::where('nombre', 'Cocina')->value('id')]]])->assertOk();
        $this->assertNotNull($latteHelado->fresh()->area_preparacion_id);

        $this->postJson('/api/mesas/config/terminales', ['nombre' => 'TERRAZA 1', 'tipo' => 'mesa', 'zona' => 'Terraza', 'capacidad' => 6])->assertCreated();
        // La tablet manda la capacidad vacía como null: queda la de la base (4)
        $this->postJson('/api/mesas/config/terminales', ['nombre' => 'TERRAZA 2', 'tipo' => 'mesa', 'zona' => 'Terraza', 'capacidad' => null])->assertCreated();
        $this->assertSame(4, (int) \App\Models\Terminal::where('nombre', 'TERRAZA 2')->value('capacidad'));
        $this->getJson('/api/mesas/config/terminales')->assertJsonFragment(['zonas' => ['Mostrador', 'Salón', 'Terraza']]);

        $this->actuarComo('cajero');
        $this->getJson('/api/mesas/config/terminales')->assertStatus(403);
    }

    // ── Autorización con PIN, cancelación parcial y ajustes de receta ──

    private function impresoraFalsa(): object
    {
        $transporte = new class implements Transporte {
            public array $enviados = [];
            public function enviar(Impresora $impresora, string $bytes): void
            {
                $this->enviados[] = mb_convert_encoding($bytes, 'UTF-8', 'CP850');
            }
        };
        $this->app->instance(TransporteRed::class, $transporte);
        Impresora::create(['alias' => 'Tablet', 'conexion' => 'red', 'ip_impresora' => '192.168.1.50', 'puerto' => 9100,
            'tipo_impresion' => 'escpos', 'ancho_caracteres' => 48, 'uso' => 'ambos', 'activo' => true, 'predeterminada' => true]);

        return $transporte;
    }

    public function test_enviar_solo_los_seleccionados_deja_lo_demas_sin_enviar(): void
    {
        $this->actuarComo('cajero');
        $orden = $this->abrir($this->mesa1)['id'];
        $this->agregar($orden, [$this->frappe(), ['producto_id' => $this->sandwich->id, 'cantidad' => 1]]);
        $frappe = OrdenItem::where('producto_id', $this->frappe->id)->value('id');
        $sandwich = OrdenItem::where('producto_id', $this->sandwich->id)->value('id');

        // Solo el frappe: sale la comanda de Barra y el sándwich sigue sin enviar
        $res = $this->postJson("/api/mesas/ordenes/{$orden}/enviar", ['item_ids' => [$frappe]])->assertOk();
        $this->assertCount(1, $res->json('comandas'));
        $this->assertSame('enviado', OrdenItem::find($frappe)->estado);
        $this->assertSame('pendiente', OrdenItem::find($sandwich)->estado);
        $this->assertSame(1, $res->json('orden.totales.por_enviar'));

        // Lo ya enviado no se vuelve a enviar
        $this->postJson("/api/mesas/ordenes/{$orden}/enviar", ['item_ids' => [$frappe]])->assertStatus(422)
            ->assertJsonPath('message', 'Ninguno de los seleccionados está sin enviar');

        // Después, «Enviar» manda lo que faltaba con otro número de comanda
        $this->postJson("/api/mesas/ordenes/{$orden}/enviar")->assertOk();
        $this->assertSame('enviado', OrdenItem::find($sandwich)->estado);
        $this->assertSame(2, Comanda::distinct()->count('numero'));
    }

    public function test_queda_quien_agrego_cada_producto_aunque_cobre_otro(): void
    {
        // Mesero 1 abre la mesa y pide el sándwich; mesero 2 agrega la coca (latte); cobra el cajero
        $mesero1 = $this->actuarComo('mesero', ['nombre' => 'Rosa']);
        $orden = $this->abrir($this->mesa1)['id'];
        $this->agregar($orden, [['producto_id' => $this->sandwich->id, 'cantidad' => 1]])->assertOk();
        $mesero2 = $this->actuarComo('mesero', ['nombre' => 'Luis']);
        $res = $this->agregar($orden, [['producto_id' => $this->latteHelado->id, 'cantidad' => 2]])->assertOk();

        // En la mesa cada línea dice quién la agregó
        $items = collect($res->json('orden.tickets.0.items'))->keyBy('nombre');
        $this->assertSame('Rosa', $items['Sándwich']['usuario']);
        $this->assertSame('Luis', $items['Latte helado']['usuario']);

        $this->actuarComo('cajero', ['nombre' => 'Caja']);
        $this->cobrar($orden, [], 20)->assertCreated();

        // Venta rápida: la hace (y la agrega) el mismo cajero
        $this->actuarComo('admin');
        $porProducto = collect($this->getJson('/api/reportes/productos_agregados')->assertOk()->json('reporte.filas'))->keyBy('producto');
        $this->assertSame('Rosa', $porProducto['Sándwich']['empleado']);
        $this->assertSame('Luis', $porProducto['Latte helado']['empleado']);
        $this->assertSame('MESA 1', $porProducto['Sándwich']['lugar']);
        $this->assertStringStartsWith('Cobrado en ', $porProducto['Latte helado']['estado']);
        $this->assertEquals(0.30, $porProducto['Latte helado']['importe']);

        // Filtro por empleado y lista de empleados para el filtro
        $reporte = $this->getJson('/api/reportes/productos_agregados')->json('reporte');
        $this->assertEqualsCanonicalizing(['Luis', 'Rosa'], collect($reporte['empleados'])->pluck('nombre')->all());
        $this->getJson("/api/reportes/productos_agregados?usuario_id={$mesero2->id}")
            ->assertJsonCount(1, 'reporte.filas')->assertJsonPath('reporte.filas.0.empleado', 'Luis');
        $this->assertNotNull($mesero1->id);
    }

    public function test_cancelar_parte_de_un_producto_enviado_con_el_pin_del_administrador(): void
    {
        $transporte = $this->impresoraFalsa();
        $admin = $this->crearUsuario('admin', ['nombre' => 'Gerente', 'pin' => '4321', 'pin_actualizado_at' => now()]);
        $this->actuarComo('cajero');
        $orden = $this->abrir($this->mesa1)['id'];
        $this->agregar($orden, [['producto_id' => $this->sandwich->id, 'cantidad' => 3]]);
        $this->postJson("/api/mesas/ordenes/{$orden}/enviar")->assertOk();
        $item = OrdenItem::where('producto_id', $this->sandwich->id)->value('id');
        $impresos = count($transporte->enviados);

        $datos = ['motivo' => 'Se fue uno', 'cantidad' => 1];
        $this->postJson("/api/mesas/items/{$item}/quitar", $datos)->assertStatus(403)->assertJsonPath('invalido', false);
        $this->postJson("/api/mesas/items/{$item}/quitar", [...$datos, 'autorizacion' => '0000'])
            ->assertStatus(403)->assertJsonPath('invalido', true);
        $this->postJson("/api/mesas/items/{$item}/quitar", [...$datos, 'cantidad' => 4, 'autorizacion' => '4321'])->assertStatus(422);
        $this->postJson("/api/mesas/items/{$item}/quitar", [...$datos, 'autorizacion' => '4321'])
            ->assertOk()->assertJsonPath('impresion.impresas', 1);

        // Siguen 2 en la mesa; el cancelado queda en su propia línea
        $this->assertEqualsWithDelta(2, (float) OrdenItem::find($item)->cantidad, 0.0001);
        $cancelado = OrdenItem::where('estado', 'cancelado')->first();
        $this->assertEqualsWithDelta(1, (float) $cancelado->cantidad, 0.0001);
        $this->assertSame('enviado', OrdenItem::find($item)->estado);
        $this->assertSame('pendiente', Comanda::first()->estado);
        $this->assertDatabaseHas('auditorias', ['accion' => 'cancelar_item']);
        $this->assertStringContainsString('Gerente', $this->auditoria()['autorizado_por']);

        // A cocina le llega el aviso, y lo que ya se imprimió no se vuelve a imprimir
        $this->assertCount($impresos + 1, $transporte->enviados);
        $aviso = end($transporte->enviados);
        $this->assertStringContainsString('CANCELADO', $aviso);
        $this->assertStringContainsString('Sándwich', $aviso);
        $this->assertStringContainsString('Se fue uno', $aviso);
        $this->agregar($orden, [['producto_id' => $this->frappe->id, 'cantidad' => 1, 'modificadores' => [$this->op['entera']]]]);
        $this->postJson("/api/mesas/ordenes/{$orden}/enviar")->assertOk();
        $this->assertStringNotContainsString('Sándwich', end($transporte->enviados));

        // También sirve la contraseña del administrador; cobrar solo lleva los 2 que quedan
        $this->postJson("/api/mesas/items/{$item}/quitar", ['motivo' => 'Otro', 'cantidad' => 1, 'autorizacion' => 'secreto123'])->assertOk();
        $this->assertEqualsWithDelta(1, (float) OrdenItem::find($item)->cantidad, 0.0001);
        $this->assertNotNull($admin->id);
    }

    private function auditoria(): array
    {
        return \App\Models\Auditoria::where('accion', 'cancelar_item')->latest('id')->first()->datos_despues;
    }

    public function test_anular_una_comanda_completa_pide_autorizacion(): void
    {
        $transporte = $this->impresoraFalsa();
        $this->crearUsuario('admin', ['nombre' => 'Gerente', 'pin' => '4321']);
        $this->actuarComo('cajero');
        $orden = $this->abrir($this->mesa1)['id'];
        $this->agregar($orden, [['producto_id' => $this->sandwich->id, 'cantidad' => 2]]);
        $this->postJson("/api/mesas/ordenes/{$orden}/enviar")->assertOk();
        $comanda = Comanda::first();

        $this->postJson("/api/mesas/comandas/{$comanda->id}/anular", ['motivo' => 'Mesa equivocada'])->assertStatus(403);
        $this->postJson("/api/mesas/comandas/{$comanda->id}/anular", ['motivo' => 'Mesa equivocada', 'autorizacion' => '4321'])->assertOk();

        $this->assertSame('cancelada', $comanda->fresh()->estado);
        $this->assertSame(0, OrdenItem::where('estado', '!=', 'cancelado')->count());
        $this->assertStringContainsString('CANCELADO', end($transporte->enviados));
        $this->postJson("/api/mesas/comandas/{$comanda->id}/anular", ['motivo' => 'Otra vez', 'autorizacion' => '4321'])->assertStatus(422);
    }

    public function test_el_cliente_pide_menos_de_un_ingrediente_y_solo_se_descuenta_lo_que_salio(): void
    {
        $unidad = UnidadesMedida::where('nombre', 'Unidad')->value('id');
        $huevo = Producto::create(['nombre' => 'Huevo', 'tipo' => 'bien', 'clase' => 'materia_prima', 'precio_venta' => 0,
            'costo_unitario' => 0.15, 'controla_stock' => true, 'unidad_medida_id' => $unidad]);
        Inventario::create(['producto_id' => $huevo->id, 'stock_actual' => 10]);
        $desayuno = Producto::create(['nombre' => 'Desayuno', 'tipo' => 'bien', 'clase' => 'producto_terminado', 'precio_venta' => 5.5,
            'costo_unitario' => 0, 'controla_stock' => false, 'unidad_medida_id' => $unidad]);
        $desayuno->categorias()->attach(Categoria::where('nombre', 'Comida')->value('id'));
        $receta = Receta::create(['producto_id' => $desayuno->id, 'nombre' => 'Desayuno', 'rendimiento' => 1, 'uso' => 'venta', 'version' => 1, 'activa' => true, 'usuario_id' => $this->crearUsuario('admin')->id]);
        $receta->ingredientes()->create(['producto_id' => $huevo->id, 'unidad_medida_id' => $unidad, 'cantidad' => 2, 'merma_porcentaje' => 0]);

        $this->actuarComo('cajero');
        $catalogo = collect($this->getJson('/api/mesas/catalogo')->json('productos'))->firstWhere('id', $desayuno->id);
        $this->assertSame([['producto_id' => $huevo->id, 'nombre' => 'Huevo', 'base' => 2, 'unidad' => 'u']], $catalogo['receta']);

        $orden = $this->abrir($this->mesa1)['id'];
        $this->agregar($orden, [['producto_id' => $desayuno->id, 'cantidad' => 1, 'ajustes' => [['producto_id' => $this->latteHelado->id, 'cantidad' => 0]]]])
            ->assertStatus(422);
        $this->agregar($orden, [
            ['producto_id' => $desayuno->id, 'cantidad' => 2, 'ajustes' => [['producto_id' => $huevo->id, 'cantidad' => 1]]],
            ['producto_id' => $desayuno->id, 'cantidad' => 1, 'ajustes' => [['producto_id' => $huevo->id, 'cantidad' => 0]]],
            ['producto_id' => $desayuno->id, 'cantidad' => 1, 'ajustes' => [['producto_id' => $huevo->id, 'cantidad' => 2]]], // lo normal
        ])->assertOk()
            ->assertJsonPath('orden.tickets.0.items.0.descripcion', 'Desayuno (Huevo: 1 (lleva 2))')
            ->assertJsonPath('orden.tickets.0.items.1.descripcion', 'Desayuno (sin Huevo)')
            ->assertJsonPath('orden.tickets.0.items.2.ajustes', []);

        // El precio no cambia: 4 desayunos a $5.50
        $venta = $this->cobrar($orden, [], 22)->assertCreated()->json('venta');
        $this->assertEqualsWithDelta(22, $venta['total'], 0.001);
        // 2 x 1 + 1 x 0 + 1 x 2 = 4 huevos, no 8
        $this->assertEqualsWithDelta(6, (float) Inventario::where('producto_id', $huevo->id)->value('stock_actual'), 0.0001);
        $this->assertDatabaseHas('ventas_detalle', ['nombre_producto' => 'Desayuno (sin Huevo)', 'costo_unitario' => 0]);
    }

    public function test_descuento_mayor_al_maximo_con_pin_y_pago_mixto(): void
    {
        ConfiguracionNegocio::actual()->update(['descuento_max_sin_autorizacion' => 10]);
        $admin = $this->crearUsuario('admin', ['nombre' => 'Gerente', 'pin' => '4321']);
        $this->actuarComo('cajero');
        $orden = $this->abrir($this->mesa1)['id'];
        $this->agregar($orden, [['producto_id' => $this->sandwich->id, 'cantidad' => 2]]);

        $efectivo = MetodoPago::where('nombre', 'Efectivo')->value('id');
        $tarjeta = MetodoPago::where('nombre', 'Tarjeta')->value('id');
        $datos = [
            'uuid' => (string) Str::uuid(), 'caja_id' => $this->caja->id,
            'descuento_global' => ['tipo' => 'porcentaje', 'valor' => 25],
            'pagos' => [['metodo_pago_id' => $tarjeta, 'monto' => 4], ['metodo_pago_id' => $efectivo, 'monto' => 5]],
        ];
        $this->postJson("/api/mesas/ordenes/{$orden}/cobrar", $datos)->assertStatus(403)->assertJsonPath('permiso', 'pos.descuento.autorizar');
        $venta = $this->postJson("/api/mesas/ordenes/{$orden}/cobrar", [...$datos, 'autorizacion' => '4321'])->assertCreated()->json('venta');

        // $8 - 25% = $6: $4 con tarjeta y $2 en efectivo (le dieron $5, cambio $3)
        $this->assertEqualsWithDelta(6, $venta['total'], 0.001);
        $this->assertSame($admin->id, Venta::find($venta['id'])->descuento_autorizado_por);
        $this->assertCount(2, Venta::find($venta['id'])->pagos);
        $this->assertSame('cerrada', Orden::find($orden)->estado);
    }

    public function test_cada_usuario_cambia_su_pin_y_no_se_repite(): void
    {
        $this->crearUsuario('admin', ['pin' => '1111']);
        $yo = $this->actuarComo('admin');

        $this->putJson('/api/me/pin', ['password_actual' => 'mala', 'pin' => '2468', 'pin_confirmation' => '2468'])->assertStatus(422);
        $this->putJson('/api/me/pin', ['password_actual' => 'secreto123', 'pin' => '12', 'pin_confirmation' => '12'])->assertStatus(422);
        $this->putJson('/api/me/pin', ['password_actual' => 'secreto123', 'pin' => '1111', 'pin_confirmation' => '1111'])
            ->assertStatus(422)->assertJsonPath('message', 'Ese PIN no está disponible, elige otro');
        $this->putJson('/api/me/pin', ['password_actual' => 'secreto123', 'pin' => '2468', 'pin_confirmation' => '2468'])->assertOk();

        $this->assertNotNull($yo->fresh()->pin_actualizado_at);
        $this->assertNotSame('2468', $yo->fresh()->pin); // guardado con hash
        $this->getJson('/api/me')->assertJsonPath('tiene_pin', true);
        $this->assertDatabaseHas('auditorias', ['accion' => 'cambiar_pin']);
    }

    public function test_bloqueo_de_pantalla_con_pin_y_el_admin_lo_resetea(): void
    {
        $mesero = $this->crearUsuario('mesero', ['pin' => '4321']);
        $this->actuarComo('admin');

        // El admin borra el PIN que el mesero olvidó (un mesero no puede hacerlo)
        $this->deleteJson("/api/admin/usuarios/{$mesero->id}/pin")->assertOk();
        $this->assertNull($mesero->fresh()->pin);
        $this->assertNull($mesero->fresh()->pin_actualizado_at);
        $this->assertDatabaseHas('auditorias', ['accion' => 'resetear_pin']);
        $this->deleteJson("/api/admin/usuarios/{$mesero->id}/pin")->assertStatus(422);

        $mesero->forceFill(['pin' => '4321'])->save();
        \Laravel\Sanctum\Sanctum::actingAs($mesero->fresh());
        $this->deleteJson("/api/admin/usuarios/{$mesero->id}/pin")->assertStatus(403);

        // Desbloquea con su PIN o su contraseña; otro PIN no sirve
        $this->postJson('/api/me/desbloquear', ['codigo' => '9999'])->assertStatus(422)->assertJsonPath('message', 'PIN incorrecto');
        $this->postJson('/api/me/desbloquear', ['codigo' => '4321'])->assertOk();
        $this->postJson('/api/me/desbloquear', ['codigo' => 'secreto123'])->assertOk();

        // Cinco intentos fallidos seguidos y espera
        for ($i = 0; $i < 25; $i++) {
            $this->postJson('/api/me/desbloquear', ['codigo' => '0000']);
        }
        $this->postJson('/api/me/desbloquear', ['codigo' => '4321'])->assertStatus(429)->assertJsonStructure(['segundos']);
    }

    // ── Pedidos de mostrador (venta rápida: comanda primero, cobro después) ──

    private function pedidoMostrador(string $nombre, array $items, ?string $uuid = null)
    {
        return $this->postJson('/api/mostrador/pedidos', [
            'uuid' => $uuid ?? (string) Str::uuid(), 'caja_id' => $this->caja->id, 'nombre' => $nombre, 'items' => $items,
        ]);
    }

    public function test_pedido_de_mostrador_envia_la_comanda_y_se_cobra_despues(): void
    {
        $transporte = $this->impresoraFalsa();
        $this->actuarComo('cajero');

        // La caja de mostrador no se abre desde el mapa de mesas
        $caja = Terminal::where('tipo', 'caja')->first();
        $this->postJson("/api/mesas/terminales/{$caja->id}/abrir")->assertStatus(422);

        $uuid = (string) Str::uuid();
        $juan = $this->pedidoMostrador('Juan', [['producto_id' => $this->sandwich->id, 'cantidad' => 1, 'notas' => 'sin cebolla']], $uuid)
            ->assertCreated()->assertJsonPath('pedido.nombre', 'Juan')->assertJsonPath('pedido.por_cobrar', 4)->json('pedido');
        // La comanda sale con el nombre del cliente
        $this->assertCount(1, $transporte->enviados);
        $this->assertStringContainsString('Juan', $transporte->enviados[0]);
        $this->assertStringContainsString('#000001', $transporte->enviados[0]);

        // Reintento del mismo envío: no duplica
        $this->pedidoMostrador('Juan', [['producto_id' => $this->sandwich->id, 'cantidad' => 1]], $uuid)->assertOk();
        $this->assertCount(1, $transporte->enviados);
        $this->assertEquals(1, OrdenItem::count());

        // Otro cliente al mismo tiempo: es otro pedido, no se mezcla
        $ana = $this->pedidoMostrador('Ana', [$this->frappe(['entera'])])->assertCreated()->json('pedido');
        $this->assertSame($juan['orden_id'], $ana['orden_id']);
        $this->assertNotSame($juan['ticket_id'], $ana['ticket_id']);

        // Juan pide algo más: también se envía
        $this->postJson("/api/mostrador/pedidos/{$juan['ticket_id']}/items", ['items' => [['producto_id' => $this->frappe->id, 'cantidad' => 1, 'modificadores' => [$this->op['entera']]]]])
            ->assertOk()->assertJsonPath('pedido.por_cobrar', 4 + 3.15);

        $this->getJson("/api/mostrador/pedidos?caja_id={$this->caja->id}")
            ->assertOk()->assertJsonCount(2, 'pedidos')
            ->assertJsonPath('pedidos.0.nombre', 'Juan')->assertJsonPath('pedidos.1.nombre', 'Ana');

        // Juan paga primero solo el frappé: el sándwich sigue por cobrar en su pedido
        $pedidoJuan = collect($this->getJson("/api/mostrador/pedidos?caja_id={$this->caja->id}")->json('pedidos'))->firstWhere('nombre', 'Juan');
        $frappeJuan = collect($pedidoJuan['items'])->firstWhere('producto_id', $this->frappe->id)['id'];
        $this->cobrar($juan['orden_id'], ['ticket_id' => $juan['ticket_id'], 'item_ids' => [$frappeJuan]], 5)
            ->assertCreated()->assertJsonPath('venta.total', 3.15);
        $this->getJson("/api/mostrador/pedidos?caja_id={$this->caja->id}")
            ->assertJsonPath('pedidos.0.nombre', 'Juan')->assertJsonPath('pedidos.0.por_cobrar', 4);

        // Se cobra el resto de Juan; el de Ana sigue pendiente
        $this->cobrar($juan['orden_id'], ['ticket_id' => $juan['ticket_id']], 10)->assertCreated()->assertJsonPath('venta.total', 4);
        $this->getJson("/api/mostrador/pedidos?caja_id={$this->caja->id}")->assertJsonCount(1, 'pedidos')->assertJsonPath('pedidos.0.nombre', 'Ana');

        // Una mesa con algo por cobrar se avisa al cerrar el turno
        $mesa = $this->abrir($this->mesa1)['id'];
        $this->agregar($mesa, [['producto_id' => $this->latteHelado->id, 'cantidad' => 2]])->assertOk();
        $this->getJson("/api/mostrador/pedidos?caja_id={$this->caja->id}")->assertJsonPath('mesas_por_cobrar', ['MESA 1']);

        // Al cobrar el último pedido la orden de mostrador se cierra; el siguiente abre otra
        $this->cobrar($ana['orden_id'], ['ticket_id' => $ana['ticket_id']], 10)->assertCreated();
        $this->assertSame('cerrada', \App\Models\Orden::find($ana['orden_id'])->estado);
        $nuevo = $this->pedidoMostrador('Luis', [['producto_id' => $this->latteHelado->id, 'cantidad' => 1]])->assertCreated()->json('pedido');
        $this->assertNotSame($ana['orden_id'], $nuevo['orden_id']);

        // Sin nombre no se envía
        $this->pedidoMostrador('', [['producto_id' => $this->latteHelado->id, 'cantidad' => 1]])
            ->assertStatus(422)->assertJsonPath('message', 'Escribe el nombre del cliente para llamarlo');
    }
}
