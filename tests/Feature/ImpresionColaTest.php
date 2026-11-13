<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\Impresora;
use App\Models\Inventario;
use App\Models\MetodoPago;
use App\Models\Producto;
use App\Models\TrabajoImpresion;
use App\Models\UnidadesMedida;
use App\Services\CajaService;
use App\Support\Impresion\Transporte;
use App\Support\Impresion\TransporteRed;
use Database\Seeders\CajaSeeder;
use Database\Seeders\UnidadesMedidaSeeder;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Servidor en la nube: las impresoras con conexión «dispositivo» no se imprimen desde
 * el servidor; el trabajo queda en una cola que toma la tablet del local y lo manda
 * por Wi-Fi a la impresora.
 */
class ImpresionColaTest extends TestCase
{
    protected bool $turnoSigueAlUsuario = true;

    private Caja $caja;
    private Producto $pan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CajaSeeder::class, UnidadesMedidaSeeder::class]);

        // El servidor nunca debe intentar conectarse a la impresora del local
        $this->app->instance(TransporteRed::class, new class implements Transporte {
            public function enviar(Impresora $impresora, string $bytes): void
            {
                throw new \LogicException('El servidor no debe imprimir en una impresora de la tablet');
            }
        });

        $this->caja = Caja::first();
        $this->pan = Producto::create(['nombre' => 'Pan francés', 'tipo' => 'bien', 'clase' => 'producto_terminado', 'precio_venta' => 0.15,
            'unidad_medida_id' => UnidadesMedida::where('nombre', 'Unidad')->value('id')]);
        Inventario::create(['producto_id' => $this->pan->id, 'stock_actual' => 100]);
        CajaService::abrir($this->caja->id, 20, $this->crearUsuario('cajero')->id);
    }

    private function impresora(array $datos = []): Impresora
    {
        return Impresora::create(array_merge([
            'alias' => 'Star mostrador', 'conexion' => 'dispositivo', 'ip_impresora' => '192.168.1.69', 'puerto' => 9100,
            'tipo_impresion' => 'escpos', 'ancho_caracteres' => 48, 'uso' => 'ambos', 'activo' => true, 'predeterminada' => true,
            'cash_drawer' => 'ninguno',
        ], $datos));
    }

    private function venta(): array
    {
        return $this->postJson('/api/pos/ventas', [
            'uuid' => (string) Str::uuid(), 'caja_id' => $this->caja->id,
            'items' => [['producto_id' => $this->pan->id, 'cantidad' => 2]],
            'pagos' => [['metodo_pago_id' => MetodoPago::where('nombre', 'Efectivo')->value('id'), 'monto' => 1]],
        ])->assertCreated()->json('venta');
    }

    private function tomar(string $dispositivo = 'tablet-caja')
    {
        return $this->postJson('/api/impresion/cola/tomar', ['dispositivo' => $dispositivo])->assertOk()->json('trabajos');
    }

    public function test_el_ticket_queda_en_cola_y_lo_imprime_una_sola_tablet(): void
    {
        $this->impresora();
        $this->actuarComo('cajero');
        $venta = $this->venta();

        $this->postJson("/api/impresion/ventas/{$venta['id']}", ['caja_id' => $this->caja->id])->assertOk()
            ->assertJsonPath('impreso', true)->assertJsonPath('en_cola', 1)
            ->assertJsonPath('message', 'Enviado a la tablet que imprime');
        $trabajo = TrabajoImpresion::first();
        $this->assertSame('pendiente', $trabajo->estado);

        // La tablet recibe la IP, el puerto y los bytes listos para mandar
        $cola = $this->tomar();
        $this->assertCount(1, $cola);
        $this->assertSame('192.168.1.69', $cola[0]['ip']);
        $this->assertSame(9100, $cola[0]['puerto']);
        $this->assertSame($trabajo->contenido, $cola[0]['bytes']);
        $this->assertStringContainsString('Pan francés', mb_convert_encoding(base64_decode($cola[0]['bytes']), 'UTF-8', 'CP850'));

        // Mientras la primera lo imprime, otra tablet no lo recibe
        $this->assertSame([], $this->tomar('tablet-salon'));

        $this->postJson("/api/impresion/trabajos/{$trabajo->id}/resultado", ['ok' => true])->assertOk()->assertJsonPath('estado', 'impreso');
        $this->assertNotNull($trabajo->fresh()->impreso_at);
        $this->assertSame(1, $trabajo->fresh()->intentos);
        // Un aviso tardío de error de otra tablet no deshace lo impreso
        $this->postJson("/api/impresion/trabajos/{$trabajo->id}/resultado", ['ok' => false, 'error' => 'x'])->assertJsonPath('estado', 'impreso');
    }

    public function test_si_la_tablet_falla_queda_el_error_y_reintentar_lo_regresa_a_la_cola(): void
    {
        $this->impresora();
        $this->actuarComo('admin');
        $venta = $this->venta();
        $this->postJson("/api/impresion/ventas/{$venta['id']}", ['caja_id' => $this->caja->id])->assertOk();
        $id = $this->tomar()[0]['id'];

        $this->postJson("/api/impresion/trabajos/{$id}/resultado", ['ok' => false, 'error' => 'La impresora no responde (192.168.1.69)'])
            ->assertJsonPath('estado', 'error');
        $this->assertSame('La impresora no responde (192.168.1.69)', TrabajoImpresion::find($id)->error);
        $this->assertSame([], $this->tomar());

        $this->postJson("/api/impresion/trabajos/{$id}/reintentar")->assertOk()->assertJsonPath('en_cola', 1);
        $this->assertSame('pendiente', TrabajoImpresion::find($id)->estado);
        $this->assertCount(1, $this->tomar());
    }

    public function test_lo_viejo_no_se_imprime_solo_y_la_star_llega_convertida(): void
    {
        $this->impresora(['tipo_impresion' => 'star_raster']);
        $this->actuarComo('admin');

        // La prueba de impresión responde bien aunque no salga del servidor
        $impresora = Impresora::first();
        $this->postJson("/api/admin/impresora/{$impresora->id}/test")->assertOk()
            ->assertJsonPath('message', 'Enviado a la tablet que imprime: debe salir en unos segundos');

        $cola = $this->tomar();
        $this->assertCount(1, $cola);
        $guardado = TrabajoImpresion::find($cola[0]['id'])->contenido;
        $this->assertNotSame($guardado, $cola[0]['bytes'], 'A la Star se le manda la imagen, no el ESC/POS');

        // Un trabajo de hace una hora ya no sale solo (podría imprimir algo que ya no importa)
        $viejo = TrabajoImpresion::create(['impresora_id' => $impresora->id, 'tipo' => 'ticket', 'estado' => 'pendiente', 'contenido' => base64_encode('x')]);
        TrabajoImpresion::whereKey($viejo->id)->update(['updated_at' => now()->subHour()]);
        $this->travel(1)->minutes();
        $this->assertNotContains($viejo->id, array_column($this->tomar('otra'), 'id'));
    }

    public function test_solo_trabajos_de_impresoras_de_la_tablet(): void
    {
        $red = $this->impresora(['alias' => 'Cocina', 'conexion' => 'red', 'predeterminada' => false, 'ip_impresora' => '10.0.0.9']);
        $trabajo = TrabajoImpresion::create(['impresora_id' => $red->id, 'tipo' => 'comanda', 'estado' => 'pendiente', 'contenido' => base64_encode('x')]);
        $this->actuarComo('cajero');

        $this->assertSame([], $this->tomar());
        $this->postJson("/api/impresion/trabajos/{$trabajo->id}/resultado", ['ok' => true])->assertStatus(422);
        $this->postJson('/api/impresion/cola/tomar', [])->assertStatus(422);
    }

    public function test_alta_de_impresora_de_la_tablet_pide_la_ip(): void
    {
        $this->actuarComo('admin');
        $datos = ['alias' => 'Star', 'conexion' => 'dispositivo', 'ancho_caracteres' => 48, 'uso' => 'ambos', 'cash_drawer' => 'ninguno'];

        $this->postJson('/api/admin/impresora/store', $datos)->assertStatus(422)->assertJsonPath('message', 'Indica la IP de la impresora de red');
        $this->postJson('/api/admin/impresora/store', [...$datos, 'ip_impresora' => '192.168.1.69', 'tipo_impresion' => 'star_raster'])
            ->assertCreated()->assertJsonPath('data.conexion', 'dispositivo');
    }
}
