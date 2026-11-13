<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\CategoriaGasto;
use App\Models\Gasto;
use App\Models\MetodoPago;
use App\Models\TurnoCaja;
use App\Services\CajaService;
use Database\Seeders\CajaSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GastosTest extends TestCase
{
    protected bool $turnoSigueAlUsuario = true;

    private Caja $caja;
    private TurnoCaja $turno;
    private int $gas;
    private int $luz;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CajaSeeder::class);
        Storage::fake('local');

        $this->caja = Caja::first();
        $this->gas = CategoriaGasto::where('nombre', 'Gas')->value('id');
        $this->luz = CategoriaGasto::where('nombre', 'Servicios básicos')->value('id');
        $this->turno = CajaService::abrir($this->caja->id, 50, $this->crearUsuario('cajero')->id);
    }

    private function gasto(array $extra = [])
    {
        return $this->post('/api/gastos', array_merge([
            'uuid'         => (string) Str::uuid(),
            'categoria_id' => $this->gas,
            'descripcion'  => 'Cambio de cilindro de gas',
            'monto'        => 18.50,
            'origen'       => 'caja',
            'caja_id'      => $this->caja->id,
        ], $extra), ['Accept' => 'application/json']);
    }

    public function test_gasto_desde_caja_genera_egreso(): void
    {
        $this->actuarComo('cajero');

        $this->gasto()->assertCreated()
            ->assertJsonPath('gasto.numero', 'G-000001')
            ->assertJsonPath('gasto.origen', 'caja')
            ->assertJsonPath('gasto.turno_id', $this->turno->id);

        $resumen = CajaService::resumen($this->turno);
        $this->assertEquals(31.5, $resumen['efectivo_esperado']);
        $this->assertEquals(18.5, $resumen['por_tipo']['gasto']);
        $this->assertDatabaseHas('auditorias', ['modulo' => 'gastos', 'accion' => 'registrar']);
    }

    public function test_gasto_desde_caja_no_supera_el_efectivo_ni_sin_turno(): void
    {
        $usuario = $this->actuarComo('admin');

        $this->gasto(['monto' => 80])->assertStatus(422)->assertJsonPath('message', 'No hay suficiente efectivo en caja: disponible $50.00');
        $this->assertSame(0, Gasto::count());

        CajaService::cerrar($this->turno, null, 50, $usuario->id);
        $this->gasto()->assertStatus(422)->assertJsonPath('message', 'Abre el turno de caja para pagar el gasto desde la caja');
    }

    public function test_gasto_fuera_de_caja_con_comprobante(): void
    {
        $this->actuarComo('admin');

        $id = $this->gasto([
            'categoria_id'     => $this->luz,
            'descripcion'      => 'Recibo de energía octubre',
            'monto'            => 120,
            'origen'           => 'externo',
            'caja_id'          => null,
            'metodo_pago_id'   => MetodoPago::where('nombre', 'Transferencia')->value('id'),
            'referencia'       => 'TR-4455',
            'numero_documento' => 'REC-889',
            'comprobante'      => UploadedFile::fake()->image('recibo.jpg'),
        ])->assertCreated()
            ->assertJsonPath('gasto.origen', 'externo')
            ->assertJsonPath('gasto.tiene_comprobante', true)
            ->json('gasto.id');

        $this->assertEquals(50, CajaService::resumen($this->turno)['efectivo_esperado']); // no toca la caja
        Storage::disk('local')->assertExists(Gasto::find($id)->comprobante);
        $this->get("/api/gastos/{$id}/comprobante")->assertOk();
    }

    public function test_comprobante_debe_ser_imagen_o_pdf(): void
    {
        $this->actuarComo('admin');

        $this->gasto(['comprobante' => UploadedFile::fake()->create('virus.exe', 10)])->assertStatus(422)
            ->assertJsonPath('message', 'El comprobante debe ser una imagen (JPG, PNG, WEBP) o un PDF');
    }

    public function test_reintento_no_duplica(): void
    {
        $this->actuarComo('cajero');
        $uuid = (string) Str::uuid();

        $this->gasto(['uuid' => $uuid])->assertCreated();
        $this->gasto(['uuid' => $uuid])->assertOk();
        $this->assertSame(1, Gasto::count());
    }

    public function test_anular_gasto_solo_lo_hace_el_administrador_y_devuelve_el_dinero(): void
    {
        $admin = $this->crearUsuario('admin', ['nombre' => 'Gerente']);
        $this->actuarComo('cajero');
        $id = $this->gasto()->json('gasto.id');

        $this->postJson("/api/gastos/{$id}/anular", ['motivo' => 'Monto equivocado'])->assertStatus(403)
            ->assertJsonPath('status', 'SIN_PERMISO');
        Sanctum::actingAs($admin, ['*'], 'sanctum');
        $this->postJson("/api/gastos/{$id}/anular", ['motivo' => 'Monto equivocado'])->assertOk()
            ->assertJsonPath('gasto.anulado', true);

        $this->assertEquals(50, CajaService::resumen($this->turno)['efectivo_esperado']);
    }

    public function test_resumen_por_categoria(): void
    {
        $this->actuarComo('admin');
        $this->gasto(['monto' => 20]);
        $this->gasto(['monto' => 10]);
        $this->gasto(['categoria_id' => $this->luz, 'monto' => 90, 'origen' => 'externo', 'caja_id' => null]);
        $anulado = $this->gasto(['monto' => 5])->json('gasto.id');
        $this->postJson("/api/gastos/{$anulado}/anular", ['motivo' => 'Duplicado']);

        $this->getJson('/api/gastos/resumen')->assertOk()
            ->assertJsonPath('resumen.total', 120)
            ->assertJsonPath('resumen.desde_caja', 30)
            ->assertJsonPath('resumen.fuera_de_caja', 90)
            ->assertJsonPath('resumen.por_categoria.0.categoria', 'Servicios básicos')
            ->assertJsonPath('resumen.por_categoria.0.porcentaje', 75);

        $this->getJson('/api/gastos?estado=vigentes')->assertJsonPath('total_monto', 120)->assertJsonCount(3, 'gastos');
    }

    public function test_categorias_y_permisos(): void
    {
        $this->actuarComo('cajero');
        $this->getJson('/api/gastos/categorias')->assertOk()->assertJsonCount(10, 'categorias');
        $this->postJson('/api/gastos/categorias', ['nombre' => 'Publicidad'])->assertStatus(403);
        $this->getJson('/api/gastos/resumen')->assertStatus(403);

        $this->actuarComo('encargado_inventario');
        $this->getJson('/api/gastos')->assertStatus(403);

        // Ver gastos no basta para administrar categorías: hace falta gastos.categorias
        $mesero = $this->actuarComo('mesero');
        $this->postJson('/api/gastos/categorias', ['nombre' => 'Internet'])->assertStatus(403);
        $mesero->givePermissionTo('gastos.categorias');
        $id = $this->postJson('/api/gastos/categorias', ['nombre' => 'Internet'])->assertCreated()->json('categoria.id');
        $this->putJson("/api/gastos/categorias/{$id}", ['nombre' => 'Internet y cable', 'activo' => false])->assertOk();
        $this->getJson('/api/gastos/categorias')->assertJsonMissing(['nombre' => 'Internet y cable']);
        $this->getJson('/api/gastos/categorias?todas=1')->assertJsonFragment(['nombre' => 'Internet y cable', 'activo' => false]);

        $this->actuarComo('admin');
        $this->postJson('/api/gastos/categorias', ['nombre' => 'Publicidad'])->assertCreated();
        $this->postJson('/api/gastos/categorias', ['nombre' => 'Gas'])->assertStatus(422)
            ->assertJsonPath('message', 'Ya existe una categoría con ese nombre');
    }
}
