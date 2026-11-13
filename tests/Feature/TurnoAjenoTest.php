<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\Inventario;
use App\Models\MetodoPago;
use App\Models\Producto;
use App\Models\TurnoCaja;
use App\Models\UnidadesMedida;
use App\Models\Venta;
use App\Services\CajaService;
use Database\Seeders\CajaSeeder;
use Database\Seeders\UnidadesMedidaSeeder;
use Illuminate\Support\Str;
use Tests\TestCase;

// Sin «caja.turno_compartido» (rol de prueba cajero), el turno de otro solo se puede cerrar.
// El mesero sí lo tiene: varios meseros con su tablet cobran en el turno de quien abrió la caja.
class TurnoAjenoTest extends TestCase
{
    private Caja $caja;
    private TurnoCaja $turno;
    private Producto $latteHelado;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CajaSeeder::class, UnidadesMedidaSeeder::class]);

        $this->caja = Caja::first();
        $this->latteHelado = Producto::create([
            'nombre' => 'Latte helado', 'tipo' => 'bien', 'clase' => 'producto_terminado',
            'precio_venta' => 1, 'costo_unitario' => 0.5,
            'unidad_medida_id' => UnidadesMedida::where('nombre', 'Unidad')->value('id'),
        ]);
        Inventario::create(['producto_id' => $this->latteHelado->id, 'stock_actual' => 50, 'stock_minimo' => 0]);

        $mesero1 = $this->crearUsuario('mesero', ['nombre' => 'Mesero 1']);
        $this->turno = CajaService::abrir($this->caja->id, 20, $mesero1->id);
    }

    private function vender(array $extra = [])
    {
        return $this->postJson('/api/pos/ventas', array_merge([
            'uuid'    => (string) Str::uuid(),
            'caja_id' => $this->caja->id,
            'items'   => [['producto_id' => $this->latteHelado->id, 'cantidad' => 1]],
            'pagos'   => [['metodo_pago_id' => MetodoPago::where('nombre', 'Efectivo')->value('id'), 'monto' => 1]],
        ], $extra));
    }

    public function test_sin_permiso_no_vende_ni_mueve_dinero_en_el_turno_ajeno(): void
    {
        $this->actuarComo('cajero');

        $this->vender()->assertStatus(422)
            ->assertJsonPath('message', 'La caja tiene abierto el turno de Mesero 1. Ciérralo y abre el tuyo para seguir.');

        $this->postJson("/api/caja/turnos/{$this->turno->id}/movimientos", [
            'tipo' => 'ingreso', 'monto' => 5, 'concepto' => 'Prueba',
        ])->assertStatus(422);

        $this->assertSame(0, Venta::count());
    }

    public function test_turno_actual_avisa_que_es_ajeno(): void
    {
        $this->actuarComo('cajero');

        $this->getJson("/api/caja/turno-actual?caja_id={$this->caja->id}")->assertOk()
            ->assertJsonPath('turno_ajeno', true)
            ->assertJsonPath('turno.usuario_apertura', 'Mesero 1');
    }

    public function test_puede_cerrar_el_turno_ajeno_y_abrir_el_suyo(): void
    {
        $mesero2 = $this->actuarComo('cajero', ['nombre' => 'Mesero 2']);

        $this->postJson("/api/caja/turnos/{$this->turno->id}/cerrar", ['efectivo_contado' => 20])->assertOk()
            ->assertJsonPath('turno.usuario_apertura', 'Mesero 1')
            ->assertJsonPath('turno.usuario_cierre', 'Mesero 2');

        $this->postJson('/api/caja/turnos/abrir', ['caja_id' => $this->caja->id, 'fondo_inicial' => 20])->assertCreated();
        $this->getJson("/api/caja/turno-actual?caja_id={$this->caja->id}")->assertJsonPath('turno_ajeno', false);

        $this->vender()->assertCreated();
        $this->assertSame($mesero2->id, TurnoCaja::where('estado', 'abierto')->value('usuario_apertura_id'));
    }

    public function test_el_admin_puede_ayudar_en_el_turno_de_un_mesero(): void
    {
        $this->actuarComo('admin');

        $this->getJson("/api/caja/turno-actual?caja_id={$this->caja->id}")->assertJsonPath('turno_ajeno', false);
        $this->vender()->assertCreated();
    }

    public function test_una_venta_sin_conexion_no_se_rechaza_aunque_el_turno_sea_ajeno(): void
    {
        $this->actuarComo('mesero');

        $this->postJson('/api/pos/sync', ['ventas' => [[
            'uuid'               => (string) Str::uuid(),
            'caja_id'            => $this->caja->id,
            'turno_id'           => $this->turno->id,
            'numero_provisional' => 'OFF-1-000001',
            'fecha_venta'        => now()->subMinutes(5)->toIso8601String(),
            'items'              => [['producto_id' => $this->latteHelado->id, 'cantidad' => 1, 'precio_unitario' => 1]],
            'pagos'              => [['metodo_pago_id' => MetodoPago::where('nombre', 'Efectivo')->value('id'), 'monto' => 1]],
        ]]])->assertOk();
        $this->assertSame(1, Venta::count());
    }

    public function test_otros_meseros_cobran_en_el_turno_de_quien_abrio(): void
    {
        $mesero2 = $this->actuarComo('mesero', ['nombre' => 'Mesero 2']);
        $this->getJson("/api/caja/turno-actual?caja_id={$this->caja->id}")->assertOk()
            ->assertJsonPath('turno_ajeno', false)
            ->assertJsonPath('turno.usuario_apertura', 'Mesero 1');
        $this->vender()->assertCreated();
        $this->getJson("/api/caja/turnos/{$this->turno->id}")->assertOk();

        $mesero3 = $this->actuarComo('mesero', ['nombre' => 'Mesero 3']);
        $this->vender()->assertCreated();
        $this->vender()->assertCreated();

        // Todo cae al turno de Mesero 1, pero cada venta guarda quién la cobró
        $this->assertSame(3, Venta::where('turno_id', $this->turno->id)->count());
        $this->assertSame(1, Venta::where('usuario_id', $mesero2->id)->count());
        $this->assertSame(2, Venta::where('usuario_id', $mesero3->id)->count());
        $this->assertSame(1, TurnoCaja::where('estado', 'abierto')->count());
    }
}
