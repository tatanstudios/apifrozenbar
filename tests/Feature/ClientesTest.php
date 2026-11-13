<?php

namespace Tests\Feature;

use App\Models\Abono;
use App\Models\Caja;
use App\Models\Cliente;
use App\Models\CuentaCobrar;
use App\Models\Inventario;
use App\Models\MetodoPago;
use App\Models\Pedido;
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

class ClientesTest extends TestCase
{
    protected bool $turnoSigueAlUsuario = true;

    private Caja $caja;
    private TurnoCaja $turno;
    private Producto $pan;
    private Producto $pastel;
    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CajaSeeder::class, UnidadesMedidaSeeder::class]);

        $this->caja = Caja::first();
        $unidad = UnidadesMedida::where('nombre', 'Unidad')->value('id');

        $this->pan = Producto::create([
            'nombre' => 'Pan francés', 'tipo' => 'bien', 'clase' => 'producto_terminado',
            'precio_venta' => 0.50, 'costo_unitario' => 0.10, 'unidad_medida_id' => $unidad,
        ]);
        Inventario::create(['producto_id' => $this->pan->id, 'stock_actual' => 100]);

        $this->pastel = Producto::create([
            'nombre' => 'Pastel de tres leches', 'tipo' => 'bien', 'clase' => 'producto_terminado',
            'precio_venta' => 25, 'costo_unitario' => 8, 'controla_stock' => false, 'unidad_medida_id' => $unidad,
        ]);

        $this->cliente = Cliente::create(['nombre' => 'Tienda Don Pepe', 'limite_credito' => 20, 'dias_credito' => 15]);
        $this->turno = CajaService::abrir($this->caja->id, 10, $this->crearUsuario('cajero')->id);
    }

    private function metodo(string $nombre): int
    {
        return MetodoPago::where('nombre', $nombre)->value('id');
    }

    private function venderCredito(float $cantidad, array $extra = [], array $pagos = null)
    {
        $total = round($cantidad * 0.50, 2);

        return $this->postJson('/api/pos/ventas', array_merge([
            'uuid'       => (string) Str::uuid(),
            'caja_id'    => $this->caja->id,
            'cliente_id' => $this->cliente->id,
            'items'      => [['producto_id' => $this->pan->id, 'cantidad' => $cantidad]],
            'pagos'      => $pagos ?? [['metodo_pago_id' => $this->metodo('Crédito'), 'monto' => $total]],
        ], $extra));
    }

    private function abonar(float $monto, array $extra = [])
    {
        return $this->postJson("/api/clientes/{$this->cliente->id}/abonos", array_merge([
            'uuid'    => (string) Str::uuid(),
            'caja_id' => $this->caja->id,
            'monto'   => $monto,
        ], $extra));
    }

    private function saldo(): float
    {
        return (float) $this->cliente->fresh()->saldo;
    }

    // ── Clientes ──────────────────────────────────────────────

    public function test_crear_buscar_y_editar_cliente(): void
    {
        $this->actuarComo('cajero');

        $id = $this->postJson('/api/clientes', [
            'nombre' => 'María López', 'documento' => '01234567-8', 'telefono' => '7777-0000', 'limite_credito' => 50,
        ])->assertCreated()->json('cliente.id');

        $this->postJson('/api/clientes', ['nombre' => 'Otra', 'documento' => '01234567-8'])
            ->assertStatus(422)->assertJsonPath('message', 'Ya hay un cliente con ese DUI o NIT');

        $this->getJson('/api/clientes?buscar=7777')->assertOk()
            ->assertJsonPath('clientes.0.nombre', 'María López')
            ->assertJsonPath('clientes.0.credito_disponible', 50);

        $this->putJson("/api/clientes/{$id}", ['nombre' => 'María López de Pérez', 'documento' => '01234567-8', 'activo' => false])
            ->assertOk()->assertJsonPath('cliente.activo', false);
        $this->getJson('/api/clientes?buscar=María')->assertJsonCount(0, 'clientes');
        $this->assertDatabaseHas('auditorias', ['modulo' => 'clientes', 'accion' => 'editar']);
    }

    public function test_cliente_solo_con_nombre_como_lo_manda_la_tablet(): void
    {
        $this->actuarComo('admin');

        // La tablet manda null en todo lo que queda vacío
        $vacio = ['documento' => null, 'telefono' => null, 'correo' => null, 'direccion' => null,
            'limite_credito' => null, 'dias_credito' => null, 'notas' => null, 'activo' => null];
        $id = $this->postJson('/api/clientes', ['nombre' => 'Juan Pérez', ...$vacio])
            ->assertCreated()->assertJsonPath('cliente.limite_credito', 0)->assertJsonPath('cliente.dias_credito', 30)
            ->json('cliente.id');

        $this->putJson("/api/clientes/{$id}", ['nombre' => 'Juan Pérez López', ...$vacio])
            ->assertOk()->assertJsonPath('cliente.nombre', 'Juan Pérez López')->assertJsonPath('cliente.dias_credito', 30);
    }

    public function test_sin_permiso_de_cuentas_no_se_asigna_credito(): void
    {
        $this->actuarComo('editor')->givePermissionTo('clientes.gestionar');

        $this->postJson('/api/clientes', ['nombre' => 'Sin crédito', 'limite_credito' => 500])->assertCreated()
            ->assertJsonPath('cliente.limite_credito', 0);
    }

    // ── Venta al crédito ──────────────────────────────────────

    public function test_venta_al_credito_crea_cuenta_y_suma_saldo(): void
    {
        $this->actuarComo('cajero');

        $venta = $this->venderCredito(20)->assertCreated()
            ->assertJsonPath('venta.cliente.nombre', 'Tienda Don Pepe')
            ->assertJsonPath('venta.credito.saldo', 10)
            ->json('venta');

        $cuenta = CuentaCobrar::first();
        $this->assertSame($venta['id'], $cuenta->venta_id);
        $this->assertSame(today()->addDays(15)->toDateString(), $cuenta->fecha_vencimiento->toDateString());
        $this->assertEquals(10, $this->saldo());

        // El crédito aparece en el cuadre por método pero no en el efectivo
        $resumen = CajaService::resumen($this->turno);
        $this->assertEquals(10, $resumen['efectivo_esperado']);
        $this->assertEquals(10, collect($resumen['por_metodo'])->firstWhere('tipo', 'credito')['neto']);
    }

    public function test_pago_mixto_efectivo_y_credito(): void
    {
        $this->actuarComo('cajero');

        $this->venderCredito(20, [], [
            ['metodo_pago_id' => $this->metodo('Efectivo'), 'monto' => 5],
            ['metodo_pago_id' => $this->metodo('Crédito'), 'monto' => 6],
        ])->assertCreated()->assertJsonPath('venta.cambio', 1);

        $this->assertEquals(6, $this->saldo());
        $this->assertEquals(14, CajaService::resumen($this->turno)['efectivo_esperado']);
    }

    public function test_credito_requiere_cliente_con_limite(): void
    {
        $this->actuarComo('cajero');

        $this->venderCredito(2, ['cliente_id' => null])->assertStatus(422)
            ->assertJsonPath('message', 'Selecciona el cliente para vender al crédito');

        $this->cliente->update(['limite_credito' => 0]);
        $this->venderCredito(2)->assertStatus(422)
            ->assertJsonPath('message', 'Tienda Don Pepe no tiene crédito autorizado');

        $this->assertSame(0, Venta::count());
    }

    public function test_exceder_el_limite_solo_lo_hace_el_administrador(): void
    {
        $admin = $this->crearUsuario('admin', ['nombre' => 'Gerente']);
        $this->actuarComo('cajero');
        $this->venderCredito(30)->assertCreated(); // debe $15 de $20

        $this->venderCredito(20)->assertStatus(403)
            ->assertJsonPath('status', 'SIN_PERMISO')
            ->assertJsonPath('permiso', 'clientes.credito_exceder');

        Sanctum::actingAs($admin, ['*'], 'sanctum');
        $this->venderCredito(20)->assertCreated();

        $this->assertEquals(25, $this->saldo());
        $this->assertDatabaseHas('auditorias', ['accion' => 'credito_sobre_limite']);
    }

    public function test_venta_al_credito_sin_conexion_sobre_el_limite_queda_anotada(): void
    {
        $this->actuarComo('cajero');

        $res = $this->postJson('/api/pos/sync', ['ventas' => [[
            'uuid'       => (string) Str::uuid(),
            'caja_id'    => $this->caja->id,
            'turno_id'   => $this->turno->id,
            'cliente_id' => $this->cliente->id,
            'fecha_venta' => now()->subMinutes(5)->toIso8601String(),
            'items'      => [['producto_id' => $this->pan->id, 'cantidad' => 60, 'precio_unitario' => 0.50]],
            'pagos'      => [['metodo_pago_id' => $this->metodo('Crédito'), 'monto' => 30]],
        ]]])->assertOk();

        $res->assertJsonPath('resultados.0.estado', 'creada');
        $this->assertStringContainsString('sobre el límite', $res->json('resultados.0.conflictos.0'));
        $this->assertEquals(30, $this->saldo());
    }

    public function test_anular_venta_al_credito_anula_la_cuenta(): void
    {
        $this->actuarComo('admin');
        $venta = $this->venderCredito(20)->json('venta');

        $this->postJson("/api/pos/ventas/{$venta['id']}/anular", ['motivo' => 'Error'])->assertOk();

        $this->assertSame('anulada', CuentaCobrar::first()->estado);
        $this->assertEquals(0, $this->saldo());
    }

    public function test_no_se_anula_venta_al_credito_con_abonos(): void
    {
        $this->actuarComo('admin');
        $venta = $this->venderCredito(20)->json('venta');
        $this->abonar(4)->assertCreated();

        $this->postJson("/api/pos/ventas/{$venta['id']}/anular", ['motivo' => 'Error'])->assertStatus(422)
            ->assertJsonPath('message', 'La venta tiene abonos registrados ($4.00): anula primero los abonos o registra una devolución');
    }

    public function test_devolucion_de_venta_al_credito_descuenta_la_cuenta(): void
    {
        $this->actuarComo('admin');
        $venta = Venta::find($this->venderCredito(20)->json('venta.id'));
        $detalle = $venta->detalles()->first();

        $this->postJson("/api/pos/ventas/{$venta->id}/devoluciones", [
            'uuid'           => (string) Str::uuid(),
            'caja_id'        => $this->caja->id,
            'motivo'         => 'Pan duro',
            'metodo_pago_id' => $this->metodo('Crédito'),
            'items'          => [['venta_detalle_id' => $detalle->id, 'cantidad' => 4]],
        ])->assertCreated()->assertJsonPath('message', fn ($m) => str_contains($m, 'de la cuenta del cliente'));

        $this->assertEquals(8, $this->saldo());
        $this->assertEquals(8, (float) CuentaCobrar::first()->saldo);
        $this->assertEquals(10, CajaService::resumen($this->turno)['efectivo_esperado']);
    }

    // ── Abonos ────────────────────────────────────────────────

    public function test_abono_entra_a_caja_y_se_aplica_de_la_cuenta_mas_antigua(): void
    {
        $this->actuarComo('cajero');
        $this->venderCredito(10); // $5
        $this->venderCredito(20); // $10
        [$primera, $segunda] = CuentaCobrar::orderBy('id')->get()->all();
        $primera->update(['fecha_vencimiento' => today()->subDay()]);

        $datos = ['uuid' => (string) Str::uuid(), 'caja_id' => $this->caja->id, 'monto' => 7];
        $this->postJson("/api/clientes/{$this->cliente->id}/abonos", $datos)->assertCreated()
            ->assertJsonPath('abono.numero', 'AB-000001')
            ->assertJsonPath('cliente.saldo', 8);
        // Reintento con el mismo uuid: no duplica
        $this->postJson("/api/clientes/{$this->cliente->id}/abonos", $datos)->assertOk();

        $this->assertSame(1, Abono::count());
        $this->assertSame('pagada', $primera->fresh()->estado);
        $this->assertEquals(8, (float) $segunda->fresh()->saldo);
        $this->assertEquals(17, CajaService::resumen($this->turno)['efectivo_esperado']);
        $this->assertEquals(7, CajaService::resumen($this->turno)['por_tipo']['abono']);
    }

    public function test_abono_no_puede_superar_el_saldo_ni_ser_sin_turno(): void
    {
        $usuario = $this->actuarComo('admin');
        $this->venderCredito(10);

        $this->abonar(6)->assertStatus(422)->assertJsonPath('message', 'Tienda Don Pepe debe $5.00: el abono no puede ser mayor');

        CajaService::cerrar($this->turno, null, 10, $usuario->id);
        $this->abonar(5)->assertStatus(422)->assertJsonPath('message', 'Abre el turno de caja para recibir el abono');
    }

    public function test_anular_abono_solo_lo_hace_el_administrador_y_restaura_saldos(): void
    {
        $admin = $this->crearUsuario('admin', ['nombre' => 'Gerente']);
        $this->actuarComo('cajero');
        $this->venderCredito(20);
        $abono = $this->abonar(10)->json('abono');
        $this->assertSame('pagada', CuentaCobrar::first()->estado);

        $this->postJson("/api/clientes/abonos/{$abono['id']}/anular", ['motivo' => 'Billete falso'])
            ->assertStatus(403)->assertJsonPath('status', 'SIN_PERMISO');

        Sanctum::actingAs($admin, ['*'], 'sanctum');
        $this->postJson("/api/clientes/abonos/{$abono['id']}/anular", ['motivo' => 'Billete falso'])
            ->assertOk()->assertJsonPath('cliente.saldo', 10);

        $this->assertSame('pendiente', CuentaCobrar::first()->estado);
        $this->assertEquals(10, CajaService::resumen($this->turno)['efectivo_esperado']);
    }

    // ── Estado de cuenta ──────────────────────────────────────

    public function test_estado_de_cuenta_con_saldo_corrido_y_pdf(): void
    {
        $this->actuarComo('admin');
        $this->venderCredito(20);       // +10
        $this->travel(1)->minutes();
        $this->abonar(3);               // -3
        $this->travel(1)->minutes();
        $this->venderCredito(4);        // +2

        $estado = $this->getJson("/api/clientes/{$this->cliente->id}/estado-cuenta")->assertOk()->json('estado');

        $this->assertSame(0.0, (float) $estado['saldo_anterior']);
        $this->assertEquals([10, 7, 9], array_column($estado['movimientos'], 'saldo'));
        $this->assertEquals(9, $estado['saldo_final']);
        $this->assertCount(2, $estado['pendientes']);

        // Periodo que empieza mañana: todo queda como saldo anterior
        $manana = today()->addDay()->toDateString();
        $this->getJson("/api/clientes/{$this->cliente->id}/estado-cuenta?desde={$manana}&hasta={$manana}")
            ->assertJsonPath('estado.saldo_anterior', 9)
            ->assertJsonCount(0, 'estado.movimientos');

        $pdf = $this->get("/api/clientes/{$this->cliente->id}/estado-cuenta/pdf");
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('content-type'));
    }

    public function test_cartera_muestra_antiguedad_y_vencidas(): void
    {
        $this->actuarComo('admin');
        $this->venderCredito(20);
        $this->venderCredito(10);
        CuentaCobrar::orderBy('id')->first()->update(['fecha_vencimiento' => today()->subDays(40)]);

        $this->getJson('/api/cuentas-cobrar?estado=vencidas')->assertOk()
            ->assertJsonCount(1, 'cuentas')
            ->assertJsonPath('cuentas.0.dias_vencida', 40)
            ->assertJsonPath('resumen.total', 15)
            ->assertJsonPath('resumen.antiguedad.d31_60', 10)
            ->assertJsonPath('resumen.antiguedad.por_vencer', 5);

        $this->getJson("/api/clientes/{$this->cliente->id}")->assertJsonPath('resumen.vencido', 10);
    }

    // ── Pedidos especiales ────────────────────────────────────

    private function crearPedido(array $extra = [])
    {
        return $this->postJson('/api/pedidos-especiales', array_merge([
            'uuid'          => (string) Str::uuid(),
            'caja_id'       => $this->caja->id,
            'nombre'        => 'Ana Martínez',
            'telefono'      => '7000-1111',
            'fecha_entrega' => now()->addDays(2)->setTime(15, 0)->format('Y-m-d H:i'),
            'items'         => [['producto_id' => $this->pastel->id, 'cantidad' => 1, 'notas' => 'Feliz cumpleaños Sofía']],
            'anticipo'      => 10,
        ], $extra));
    }

    public function test_pedido_especial_con_anticipo_entra_a_caja(): void
    {
        $this->actuarComo('cajero');

        $pedido = $this->crearPedido()->assertCreated()
            ->assertJsonPath('pedido.numero', 'PE-000001')
            ->assertJsonPath('pedido.anticipo', 10)
            ->assertJsonPath('pedido.pendiente', 15)
            ->assertJsonPath('pedido.items.0.notas', 'Feliz cumpleaños Sofía')
            ->json('pedido');

        $resumen = CajaService::resumen($this->turno);
        $this->assertEquals(10, $resumen['por_tipo']['anticipo']);
        $this->assertEquals(20, $resumen['efectivo_esperado']);

        $this->postJson("/api/pedidos-especiales/{$pedido['id']}/anticipos", ['caja_id' => $this->caja->id, 'monto' => 20])
            ->assertStatus(422)->assertJsonPath('message', 'El anticipo no puede superar el total del pedido ($25.00)');
        $this->postJson("/api/pedidos-especiales/{$pedido['id']}/anticipos", ['caja_id' => $this->caja->id, 'monto' => 5])
            ->assertOk()->assertJsonPath('pedido.anticipo', 15)->assertJsonCount(2, 'pedido.anticipos');

        $this->getJson('/api/pedidos-especiales')->assertJsonCount(1, 'pedidos');
        $this->getJson('/api/pos/pedidos')->assertJsonCount(0, 'pedidos'); // no se mezcla con las precuentas
    }

    public function test_entregar_pedido_cobra_solo_lo_pendiente(): void
    {
        $this->actuarComo('cajero');
        $pedido = $this->crearPedido()->json('pedido');

        $this->postJson('/api/pos/ventas', [
            'uuid'      => (string) Str::uuid(),
            'caja_id'   => $this->caja->id,
            'pedido_id' => $pedido['id'],
            'items'     => [['producto_id' => $this->pastel->id, 'cantidad' => 1]],
            'pagos'     => [['metodo_pago_id' => $this->metodo('Efectivo'), 'monto' => 20]],
        ])->assertCreated()
            ->assertJsonPath('venta.total', 25)
            ->assertJsonPath('venta.anticipo_aplicado', 10)
            ->assertJsonPath('venta.cambio', 5);

        $this->assertSame('cobrado', Pedido::find($pedido['id'])->estado);
        // 10 de fondo + 10 de anticipo + 15 al entregar
        $this->assertEquals(35, CajaService::resumen($this->turno)['efectivo_esperado']);
    }

    public function test_pedido_no_acepta_fecha_pasada_ni_total_menor_al_anticipo(): void
    {
        $this->actuarComo('cajero');

        $this->crearPedido(['fecha_entrega' => now()->subDay()->format('Y-m-d H:i')])->assertStatus(422)
            ->assertJsonPath('message', 'La fecha de entrega no puede ser anterior a hoy');

        $pedido = $this->crearPedido(['anticipo' => 20])->json('pedido');
        $this->putJson("/api/pedidos-especiales/{$pedido['id']}", [
            'nombre'        => 'Ana Martínez',
            'fecha_entrega' => $pedido['fecha_entrega'],
            'items'         => [['producto_id' => $this->pan->id, 'cantidad' => 2]],
        ])->assertStatus(422)->assertJsonPath('message', 'El total no puede quedar por debajo del anticipo recibido ($20.00)');
    }

    public function test_cancelar_pedido_devolviendo_o_reteniendo_el_anticipo(): void
    {
        $this->actuarComo('cajero');
        $devuelto = $this->crearPedido()->json('pedido');
        $retenido = $this->crearPedido()->json('pedido');

        $this->postJson("/api/pedidos-especiales/{$devuelto['id']}/cancelar", [
            'motivo' => 'El cliente ya no lo quiere', 'devolver_anticipo' => true, 'caja_id' => $this->caja->id,
        ])->assertOk()->assertJsonPath('pedido.estado', 'cancelado');

        $this->postJson("/api/pedidos-especiales/{$retenido['id']}/cancelar", ['motivo' => 'No se presentó'])->assertOk();

        // 10 de fondo + 20 de anticipos − 10 devuelto
        $this->assertEquals(20, CajaService::resumen($this->turno)['efectivo_esperado']);
        $this->assertDatabaseHas('auditorias', ['accion' => 'cancelar_pedido']);
    }

    public function test_permisos_de_clientes_y_pedidos(): void
    {
        $this->actuarComo('encargado_inventario');
        $this->getJson('/api/clientes')->assertStatus(403);
        $this->getJson('/api/pedidos-especiales')->assertStatus(403);
        $this->getJson('/api/cuentas-cobrar')->assertStatus(403);

        $this->actuarComo('cajero');
        $this->getJson('/api/clientes')->assertOk();
        $this->getJson('/api/cuentas-cobrar')->assertOk();
    }
}
