<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\ConfiguracionNegocio;
use App\Models\MetodoPago;
use App\Models\MovimientoCaja;
use App\Models\TurnoCaja;
use App\Services\CajaService;
use Database\Seeders\CajaSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Tests\TestCase;

class CajaTest extends TestCase
{
    private Caja $caja;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CajaSeeder::class);
        $this->caja = Caja::first();
    }

    private function metodo(string $nombre): MetodoPago
    {
        return MetodoPago::where('nombre', $nombre)->firstOrFail();
    }

    // El turno lo abre y lo cierra el usuario de la sesión
    private function abrir(float $fondo = 50, array $extra = [])
    {
        return $this->postJson('/api/caja/turnos/abrir', array_merge([
            'caja_id' => $this->caja->id, 'fondo_inicial' => $fondo,
        ], $extra));
    }

    private function cerrarTurno(int $turnoId, array $datos)
    {
        return $this->postJson("/api/caja/turnos/{$turnoId}/cerrar", $datos);
    }

    private function turno(): TurnoCaja
    {
        return TurnoCaja::where('caja_id', $this->caja->id)->latest('id')->firstOrFail();
    }

    private function movimiento(string $tipo, float $monto, array $extra = [])
    {
        return $this->postJson("/api/caja/turnos/{$this->turno()->id}/movimientos", array_merge([
            'tipo' => $tipo, 'monto' => $monto, 'concepto' => "Prueba {$tipo}",
        ], $extra));
    }

    private function esperado(): float
    {
        return CajaService::resumen($this->turno())['efectivo_esperado'];
    }

    // ── Apertura ────────────────────────────────────────────────

    public function test_abrir_turno_con_denominaciones_calcula_el_fondo_en_el_servidor(): void
    {
        $cajero = $this->actuarComo('cajero');

        $this->abrir(999, ['denominaciones' => ['20' => 2, '1' => 5, '0.25' => 4, '0.10' => 0]])
            ->assertCreated()
            ->assertJsonPath('turno.fondo_inicial', 46)
            ->assertJsonPath('turno.estado', 'abierto');

        $this->assertSame($cajero->id, $this->turno()->usuario_apertura_id);
        $this->assertDatabaseHas('auditorias', ['modulo' => 'caja', 'accion' => 'abrir_turno']);
    }

    public function test_cada_empleado_entra_con_su_usuario_y_el_turno_queda_a_su_nombre(): void
    {
        $ana = $this->actuarComo('mesero', ['nombre' => 'Ana']);

        // El turno es de quien inició sesión
        $this->abrir(50)->assertCreated()->assertJsonPath('turno.usuario_apertura', 'Ana');
        $this->assertSame($ana->id, $this->turno()->usuario_apertura_id);
        $this->cerrarTurno($this->turno()->id, ['efectivo_contado' => 50])
            ->assertOk()->assertJsonPath('turno.usuario_cierre', 'Ana');

        // Ana cierra sesión y entra Luis con su usuario para abrir el siguiente turno
        $luis = $this->actuarComo('mesero', ['nombre' => 'Luis']);
        $this->abrir(50)->assertCreated()->assertJsonPath('turno.usuario_apertura', 'Luis');
        $this->cerrarTurno($this->turno()->id, ['efectivo_contado' => 50])->assertOk()->assertJsonPath('turno.usuario_cierre', 'Luis');

        $this->actuarComo('admin');
        $this->getJson('/api/caja/turnos')->assertOk()->assertJsonPath('total', 2);
        $this->getJson("/api/caja/turnos?usuario_id={$luis->id}")->assertJsonPath('total', 1);
        $this->getJson('/api/caja/responsables')->assertNotFound();
    }

    public function test_no_se_abren_dos_turnos_en_la_misma_caja(): void
    {
        $this->actuarComo('cajero');
        $this->abrir()->assertCreated();

        $this->abrir()->assertStatus(422)->assertJsonPath('status', 'BUSINESS_RULE');
        $this->assertSame(1, TurnoCaja::count());
    }

    public function test_la_base_de_datos_impide_dos_turnos_abiertos_por_caja(): void
    {
        $usuario = $this->crearUsuario('cajero');
        $datos = ['caja_id' => $this->caja->id, 'usuario_apertura_id' => $usuario->id, 'estado' => 'abierto', 'abierto_at' => now()];

        TurnoCaja::create($datos);

        $this->expectException(QueryException::class);
        TurnoCaja::create($datos);
    }

    public function test_encargado_de_inventario_no_opera_caja(): void
    {
        $this->actuarComo('encargado_inventario');
        $this->abrir()->assertStatus(403);
    }

    public function test_denominacion_invalida_o_cantidad_decimal_se_rechaza(): void
    {
        $this->actuarComo('cajero');

        $this->abrir(0, ['denominaciones' => ['3' => 1]])->assertStatus(422);
        $this->abrir(0, ['denominaciones' => ['20' => 1.5]])->assertStatus(422);
        $this->assertSame(0, TurnoCaja::count());
    }

    // ── Ingresos, egresos y anulaciones ─────────────────────────

    public function test_ingresos_y_egresos_actualizan_el_efectivo_esperado(): void
    {
        $this->actuarComo('cajero');
        $this->abrir(50)->assertCreated();

        $this->movimiento('ingreso', 20)->assertCreated();
        $this->movimiento('egreso', 15.5)->assertCreated();

        $this->assertEquals(54.5, $this->esperado());
    }

    public function test_no_se_saca_mas_efectivo_del_que_hay(): void
    {
        $this->actuarComo('cajero');
        $this->abrir(10)->assertCreated();

        $this->movimiento('egreso', 10.01)
            ->assertStatus(422)
            ->assertJsonPath('message', 'No hay suficiente efectivo en caja: disponible $10.00');
        $this->movimiento('egreso', 10)->assertCreated();
    }

    public function test_metodo_con_referencia_la_exige(): void
    {
        $this->actuarComo('cajero');
        $this->abrir()->assertCreated();
        $transferencia = $this->metodo('Transferencia');

        $this->movimiento('ingreso', 5, ['metodo_pago_id' => $transferencia->id])->assertStatus(422);
        $this->movimiento('ingreso', 5, ['metodo_pago_id' => $transferencia->id, 'referencia' => 'TR-123'])->assertCreated();

        // Una transferencia no cambia el efectivo de la gaveta
        $this->assertEquals(50, $this->esperado());
    }

    public function test_el_mismo_uuid_no_duplica_el_movimiento(): void
    {
        $this->actuarComo('cajero');
        $this->abrir()->assertCreated();
        $uuid = (string) Str::uuid();

        $this->movimiento('ingreso', 5, ['uuid' => $uuid])->assertCreated();
        $this->movimiento('ingreso', 5, ['uuid' => $uuid])->assertOk();

        $this->assertSame(1, MovimientoCaja::count());
        $this->assertEquals(55, $this->esperado());
    }

    public function test_anular_ingreso_lo_quita_del_resumen_y_queda_registrado(): void
    {
        $this->actuarComo('cajero');
        $this->abrir(50)->assertCreated();
        $id = $this->movimiento('ingreso', 20)->json('movimiento.id');

        $this->postJson("/api/caja/movimientos/{$id}/anular", [])->assertStatus(422); // motivo obligatorio
        $this->postJson("/api/caja/movimientos/{$id}/anular", ['motivo' => 'Se registró dos veces'])->assertOk();
        $this->postJson("/api/caja/movimientos/{$id}/anular", ['motivo' => 'Otra vez'])->assertStatus(422);

        $this->assertEquals(50, $this->esperado());
        $this->assertDatabaseHas('movimientos_caja', ['id' => $id, 'anulado' => true, 'motivo_anulacion' => 'Se registró dos veces']);
    }

    public function test_movimientos_de_ventas_no_se_anulan_desde_caja(): void
    {
        $cajero = $this->actuarComo('cajero');
        $this->abrir()->assertCreated();
        $venta = CajaService::registrarMovimiento($this->turno(), 'venta', 3.5, 'Venta T-000001', $cajero->id);

        $this->postJson("/api/caja/movimientos/{$venta->id}/anular", ['motivo' => 'x'])->assertStatus(422);
    }

    // ── Resumen ─────────────────────────────────────────────────

    public function test_resumen_por_metodo_y_ventas_por_empleado(): void
    {
        $ana  = $this->actuarComo('cajero', ['nombre' => 'Ana']);
        $beto = $this->crearUsuario('cajero', ['nombre' => 'Beto']);
        $this->abrir(20)->assertCreated();
        $turno = $this->turno();

        CajaService::registrarMovimiento($turno, 'venta', 10, 'V1', $ana->id);
        CajaService::registrarMovimiento($turno, 'venta', 4.25, 'V2', $ana->id, $this->metodo('Tarjeta')->id);
        CajaService::registrarMovimiento($turno, 'venta', 30, 'V3', $beto->id);

        $resumen = CajaService::resumen($turno);

        $this->assertEquals(60, $resumen['efectivo_esperado']); // 20 + 10 + 30 (la tarjeta no entra a la gaveta)
        $this->assertEquals(44.25, $resumen['totales']['ventas']);

        $tarjeta = collect($resumen['por_metodo'])->firstWhere('nombre', 'Tarjeta');
        $this->assertEquals(4.25, $tarjeta['neto']);

        $this->assertSame(['Beto', 'Ana'], array_column($resumen['ventas_por_usuario'], 'usuario'));
        $this->assertSame([1, 2], array_column($resumen['ventas_por_usuario'], 'cantidad'));
    }

    // ── Arqueo y cierre ─────────────────────────────────────────

    public function test_arqueo_parcial_calcula_la_diferencia_sin_cerrar(): void
    {
        $this->actuarComo('cajero');
        $this->abrir(50)->assertCreated();

        $this->postJson("/api/caja/turnos/{$this->turno()->id}/arqueos", ['denominaciones' => ['20' => 2, '5' => 1, '1' => 4]])
            ->assertCreated()
            ->assertJsonPath('total_contado', 49)
            ->assertJsonPath('diferencia', -1);

        $this->assertTrue($this->turno()->estaAbierto());
    }

    public function test_cerrar_guarda_la_foto_del_turno_y_bloquea_movimientos(): void
    {
        $cajero = $this->actuarComo('cajero');
        $this->abrir(50)->assertCreated();
        $turno = $this->turno();
        CajaService::registrarMovimiento($turno, 'venta', 25, 'V1', $cajero->id);
        $this->movimiento('egreso', 5)->assertCreated();

        $this->cerrarTurno($turno->id, [
            'denominaciones' => ['50' => 1, '10' => 1, '5' => 1, '1' => 4],  // 69: falta $1
            'observaciones'  => 'Cierre de la tarde',
        ])->assertOk()
            ->assertJsonPath('turno.estado', 'cerrado')
            ->assertJsonPath('turno.efectivo_esperado', 70)
            ->assertJsonPath('turno.efectivo_contado', 69)
            ->assertJsonPath('turno.diferencia', -1)
            ->assertJsonPath('turno.total_ventas', 25);

        $turno->refresh();
        $this->assertSame($cajero->id, $turno->usuario_cierre_id);
        $this->assertSame(1, $turno->arqueos()->where('tipo', 'cierre')->count());
        $this->assertNotEmpty($turno->resumen['por_metodo']);

        $this->movimiento('ingreso', 1)->assertStatus(422);
        $this->cerrarTurno($turno->id, ['efectivo_contado' => 69])->assertStatus(422);

        // La caja queda libre para el siguiente turno
        $this->abrir(69)->assertCreated();
    }

    public function test_cierre_ciego_oculta_el_esperado_al_cajero_pero_no_al_admin(): void
    {
        ConfiguracionNegocio::actual()->update(['cierre_ciego' => true]);
        $this->actuarComo('cajero');
        $this->abrir(50)->assertCreated();

        $this->getJson("/api/caja/turno-actual?caja_id={$this->caja->id}")
            ->assertOk()
            ->assertJsonPath('resumen.efectivo_esperado', null)
            ->assertJsonPath('cierre_ciego', true)
            ->assertJsonPath('aviso_cambio', true); // la ventana de cobro avisa si no alcanza el cambio (por defecto, sí)

        // Pagar de caja más de lo que hay tampoco le dice cuánto hay
        $this->movimiento('egreso', 80)->assertStatus(422)
            ->assertJsonPath('message', 'No hay suficiente efectivo en caja para sacar ese monto. Págalo desde otra cuenta o registra antes un ingreso.');

        $this->actuarComo('admin');
        $this->getJson("/api/caja/turno-actual?caja_id={$this->caja->id}")
            ->assertJsonPath('resumen.efectivo_esperado', 50);
        $this->movimiento('egreso', 80)->assertStatus(422)
            ->assertJsonPath('message', 'No hay suficiente efectivo en caja: disponible $50.00');
    }

    public function test_lo_contado_al_cerrar_queda_como_fondo_del_siguiente_turno(): void
    {
        $this->actuarComo('cajero');
        $this->abrir(50)->assertCreated();
        $this->movimiento('ingreso', 30)->assertCreated();
        $this->cerrarTurno($this->turno()->id, ['efectivo_contado' => 78])->assertOk();

        // Quien abre ve lo que quedó en la gaveta
        $this->getJson("/api/caja/turno-actual?caja_id={$this->caja->id}")
            ->assertOk()
            ->assertJsonPath('fondo_sugerido', 78)
            ->assertJsonPath('turno_anterior.efectivo_contado', 78);

        // Otro monto sin explicar se rechaza; sin monto, se abre con lo que quedó
        $this->abrir(0)->assertStatus(422);
        $this->postJson('/api/caja/turnos/abrir', ['caja_id' => $this->caja->id])
            ->assertCreated()
            ->assertJsonPath('turno.fondo_inicial', 78);

        // Con observaciones sí se acepta otro monto
        $this->cerrarTurno($this->turno()->id, ['efectivo_contado' => 78])->assertOk();
        $this->abrir(20, ['observaciones' => 'El dueño retiró $58'])
            ->assertCreated()
            ->assertJsonPath('turno.fondo_inicial', 20);
    }

    public function test_turno_actual_sin_turno_devuelve_null(): void
    {
        $this->actuarComo('cajero');

        $this->getJson("/api/caja/turno-actual?caja_id={$this->caja->id}")
            ->assertOk()
            ->assertJsonPath('turno', null)
            ->assertJsonPath('caja.nombre', 'Caja principal');
    }

    // ── Reabrir, historial y reporte ────────────────────────────

    public function test_solo_admin_reabre_y_no_si_la_caja_tiene_otro_turno(): void
    {
        $this->actuarComo('cajero');
        $this->abrir(10)->assertCreated();
        $turno = $this->turno();
        $this->cerrarTurno($turno->id, ['efectivo_contado' => 10])->assertOk();

        $this->postJson("/api/caja/turnos/{$turno->id}/reabrir", ['motivo' => 'Faltó una venta'])->assertStatus(403);

        $this->actuarComo('admin');
        $this->postJson("/api/caja/turnos/{$turno->id}/reabrir", ['motivo' => 'Faltó una venta'])
            ->assertOk()->assertJsonPath('turno.estado', 'abierto');
        $this->assertDatabaseHas('auditorias', ['accion' => 'reabrir_turno', 'descripcion' => 'Faltó una venta']);

        // Con otro turno abierto en la caja no se puede
        $this->cerrarTurno($turno->id, ['efectivo_contado' => 10])->assertOk();
        $this->abrir(10)->assertCreated();
        $this->postJson("/api/caja/turnos/{$turno->id}/reabrir", ['motivo' => 'x'])->assertStatus(422);
    }

    public function test_historial_con_totales_y_permisos(): void
    {
        $this->actuarComo('cajero');
        $this->abrir(10)->assertCreated();
        $propio = $this->turno();
        $this->cerrarTurno($propio->id, ['efectivo_contado' => 8])->assertOk();

        // El cajero no ve el historial, pero sí el reporte de su propio turno
        $this->getJson('/api/caja/turnos')->assertStatus(403);
        $this->getJson("/api/caja/turnos/{$propio->id}")->assertOk();

        $this->actuarComo('cajero');
        $this->getJson("/api/caja/turnos/{$propio->id}")->assertStatus(403);

        $this->actuarComo('admin');
        $this->getJson('/api/caja/turnos')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('totales.faltantes', -2);
    }

    // ── Configuración ───────────────────────────────────────────

    public function test_no_se_desactiva_el_ultimo_efectivo_ni_una_caja_con_turno(): void
    {
        $this->actuarComo('admin');
        $efectivo = $this->metodo('Efectivo');

        $this->putJson("/api/caja/metodos-pago/{$efectivo->id}", [
            'nombre' => 'Efectivo', 'tipo' => 'efectivo', 'afecta_efectivo' => true, 'requiere_referencia' => false, 'activo' => false,
        ])->assertStatus(422);

        $this->abrir()->assertCreated();
        $this->putJson("/api/caja/cajas/{$this->caja->id}", ['nombre' => 'Caja principal', 'activo' => false])->assertStatus(422);
    }

    public function test_catalogos_traen_cajas_metodos_activos_y_denominaciones(): void
    {
        $this->actuarComo('cajero');

        $this->metodo('Tarjeta')->update(['activo' => false]);
        $res = $this->getJson('/api/caja/catalogos')->assertOk();

        $this->assertSame(['Efectivo', 'Transferencia', 'Crédito'], array_column($res->json('metodos_pago'), 'nombre'));
        $this->assertCount(10, $res->json('denominaciones'));
        $this->assertSame('Caja principal', $res->json('cajas.0.nombre'));
    }
}
