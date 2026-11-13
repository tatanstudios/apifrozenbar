<?php

namespace Tests\Feature;

use App\Models\AreaPreparacion;
use App\Models\Caja;
use App\Models\Categoria;
use App\Models\Impresora;
use App\Models\Inventario;
use App\Models\MetodoPago;
use App\Models\Producto;
use App\Models\TrabajoImpresion;
use App\Models\TurnoCaja;
use App\Models\UnidadesMedida;
use App\Services\CajaService;
use App\Support\Impresion\EscPos;
use App\Support\Impresion\ImpresionException;
use App\Support\Impresion\Transporte;
use App\Support\Impresion\TransporteRed;
use Database\Seeders\CajaSeeder;
use Database\Seeders\UnidadesMedidaSeeder;
use Illuminate\Support\Str;
use Tests\TestCase;

// Transporte de prueba: guarda lo que se "imprime" o simula una impresora apagada
class TransporteFalso implements Transporte
{
    public array $enviados = [];
    public bool $falla = false;

    public function enviar(Impresora $impresora, string $bytes): void
    {
        if ($this->falla) {
            throw new ImpresionException("No se pudo conectar con {$impresora->alias}");
        }
        $this->enviados[] = ['impresora' => $impresora->alias, 'bytes' => $bytes];
    }

    public function texto(int $i = 0): string
    {
        return mb_convert_encoding($this->enviados[$i]['bytes'], 'UTF-8', 'CP850');
    }
}

class ImpresionTest extends TestCase
{
    protected bool $turnoSigueAlUsuario = true;

    private TransporteFalso $transporte;
    private Caja $caja;
    private TurnoCaja $turno;
    private Producto $latteHelado;
    private Producto $cafe;
    private Categoria $heladas;
    private Categoria $bebidas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CajaSeeder::class, UnidadesMedidaSeeder::class]);

        $this->transporte = new TransporteFalso();
        $this->app->instance(TransporteRed::class, $this->transporte);

        $this->caja = Caja::first();
        $unidad = UnidadesMedida::where('nombre', 'Unidad')->first();
        $this->heladas = Categoria::create(['nombre' => 'Bebidas heladas', 'estado' => true]);
        $this->bebidas = Categoria::create(['nombre' => 'Bebidas', 'estado' => true]);

        $this->latteHelado = Producto::create(['nombre' => 'Latte helado', 'tipo' => 'bien', 'clase' => 'producto_terminado', 'precio_venta' => 0.15, 'unidad_medida_id' => $unidad->id]);
        $this->cafe = Producto::create(['nombre' => 'Café con leche', 'tipo' => 'bien', 'clase' => 'reventa', 'precio_venta' => 1.25, 'unidad_medida_id' => $unidad->id]);
        Inventario::create(['producto_id' => $this->latteHelado->id, 'stock_actual' => 100]);
        Inventario::create(['producto_id' => $this->cafe->id, 'stock_actual' => 100]);
        $this->latteHelado->categorias()->sync([$this->heladas->id]);
        $this->cafe->categorias()->sync([$this->bebidas->id]);

        $this->turno = CajaService::abrir($this->caja->id, 20, $this->crearUsuario('cajero')->id);
    }

    private function impresora(array $datos = []): Impresora
    {
        return Impresora::create(array_merge([
            'alias' => 'Mostrador', 'conexion' => 'red', 'ip_impresora' => '192.168.1.50', 'puerto' => 9100,
            'tipo_impresion' => 'escpos', 'ancho_caracteres' => 48, 'uso' => 'ticket', 'cash_drawer' => 'pin2', 'activo' => true,
        ], $datos));
    }

    private function vender(array $items, string $metodo = 'Efectivo', float $monto = 10): int
    {
        return $this->postJson('/api/pos/ventas', [
            'uuid' => (string) Str::uuid(), 'caja_id' => $this->caja->id, 'items' => $items,
            'pagos' => [['metodo_pago_id' => MetodoPago::where('nombre', $metodo)->value('id'), 'monto' => $monto]],
        ])->assertCreated()->json('venta.id');
    }

    // ── Ticket ──────────────────────────────────────────────────

    public function test_ticket_lleva_datos_de_la_venta_corta_y_abre_el_cajon_si_hubo_efectivo(): void
    {
        $this->impresora(['predeterminada' => true]);
        $this->actuarComo('cajero');
        $id = $this->vender([['producto_id' => $this->latteHelado->id, 'cantidad' => 10]], 'Efectivo', 5);

        $this->postJson("/api/impresion/ventas/{$id}", ['caja_id' => $this->caja->id])
            ->assertOk()->assertJsonPath('impreso', true)->assertJsonPath('trabajos.0.estado', 'impreso');

        $texto = $this->transporte->texto();
        $this->assertStringContainsString('The Frozen Bar', $texto);
        $this->assertStringContainsString('Recibo', $texto);
        $this->assertStringContainsString('Nombre: Consumidor Final', $texto);
        $this->assertStringContainsString('No. de Venta: 000001', $texto);
        $this->assertStringContainsString('Latte helado', $texto);
        $this->assertStringContainsString('Total: $ 1.50', $texto);
        $this->assertStringContainsString('Un dólar Con Cincuenta Centavos', $texto);
        $this->assertStringContainsString('Condición de la operación: Efectivo', $texto);
        $this->assertStringContainsString('Cambio: $ 3.50', $texto);

        $bytes = $this->transporte->enviados[0]['bytes'];
        $this->assertStringContainsString("\x1Bp\x00\x19\xFA", $bytes);   // cajón por el pin 2
        $this->assertStringContainsString("\x1DV\x42\x00", $bytes);       // corte de papel
    }

    public function test_ticket_lleva_los_datos_fiscales_del_negocio(): void
    {
        \App\Models\ConfiguracionNegocio::actual()->update([
            'razon_social' => 'FLAVIOS SAS DE CV', 'direccion' => 'Plaza la constitución local #14, METAPAN',
            'nit' => '058416726', 'nrc' => '0', 'telefono' => '5644480140',
        ]);
        $this->impresora(['predeterminada' => true]);
        $this->actuarComo('cajero');
        $id = $this->vender([['producto_id' => $this->latteHelado->id, 'cantidad' => 2]], 'Efectivo', 0.30);

        $this->postJson("/api/impresion/ventas/{$id}", ['caja_id' => $this->caja->id])->assertJsonPath('impreso', true);

        $texto = $this->transporte->texto();
        foreach (['FLAVIOS SAS DE CV', 'NIT: 058416726', 'NRC: 0', 'Tipo de establecimiento:', 'Sucursal / Agencia', 'Tel: 5644480140', 'Datos del receptor', 'Total en letras:'] as $dato) {
            $this->assertStringContainsString($dato, $texto);
        }
    }

    public function test_total_en_letras(): void
    {
        $this->assertSame('Seis dólares Con Cinco Centavos', \App\Support\NumeroALetras::dolares(6.05));
        $this->assertSame('Un dólar Con Cero Centavos', \App\Support\NumeroALetras::dolares(1));
        $this->assertSame('Veintiún dólares Con Cincuenta Centavos', \App\Support\NumeroALetras::dolares(21.50));
        $this->assertSame('Ciento Quince dólares Con Un Centavo', \App\Support\NumeroALetras::dolares(115.01));
        $this->assertSame('Mil Doscientos Treinta y Cuatro dólares Con Noventa y Nueve Centavos', \App\Support\NumeroALetras::dolares(1234.99));
        $this->assertSame('Cero dólares Con Setenta y Cinco Centavos', \App\Support\NumeroALetras::dolares(0.75));
    }

    public function test_reimpresion_es_copia_y_no_abre_el_cajon(): void
    {
        $this->impresora(['predeterminada' => true]);
        $this->actuarComo('cajero');
        $id = $this->vender([['producto_id' => $this->latteHelado->id, 'cantidad' => 1]], 'Efectivo', 1);

        $this->postJson("/api/impresion/ventas/{$id}", ['copia' => true])->assertJsonPath('impreso', true);

        $this->assertStringContainsString('*** COPIA ***', $this->transporte->texto());
        $this->assertStringNotContainsString("\x1Bp", $this->transporte->enviados[0]['bytes']);
    }

    public function test_pago_con_tarjeta_no_abre_el_cajon(): void
    {
        $this->impresora(['predeterminada' => true]);
        $this->actuarComo('cajero');
        $id = $this->vender([['producto_id' => $this->cafe->id, 'cantidad' => 1]], 'Tarjeta', 1.25);

        $this->postJson("/api/impresion/ventas/{$id}")->assertJsonPath('impreso', true);
        $this->assertStringNotContainsString("\x1Bp", $this->transporte->enviados[0]['bytes']);
    }

    public function test_usa_la_impresora_de_la_caja_antes_que_la_predeterminada(): void
    {
        $this->impresora(['alias' => 'General', 'predeterminada' => true]);
        $propia = $this->impresora(['alias' => 'Caja 1', 'ip_impresora' => '192.168.1.51']);
        $this->caja->update(['impresora_id' => $propia->id]);

        $this->actuarComo('cajero');
        $id = $this->vender([['producto_id' => $this->latteHelado->id, 'cantidad' => 1]], 'Efectivo', 1);
        $this->postJson("/api/impresion/ventas/{$id}", ['caja_id' => $this->caja->id])->assertOk();

        $this->assertSame('Caja 1', $this->transporte->enviados[0]['impresora']);
    }

    public function test_sin_impresora_configurada_responde_con_el_motivo(): void
    {
        $this->actuarComo('cajero');
        $id = $this->vender([['producto_id' => $this->latteHelado->id, 'cantidad' => 1]], 'Efectivo', 1);

        $this->postJson("/api/impresion/ventas/{$id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'No hay impresora de tickets configurada para esta caja');
    }

    // ── Errores y reintentos ────────────────────────────────────

    public function test_impresora_apagada_queda_registrada_y_se_puede_reintentar(): void
    {
        $this->impresora(['predeterminada' => true]);
        $this->actuarComo('cajero');
        $id = $this->vender([['producto_id' => $this->latteHelado->id, 'cantidad' => 1]], 'Efectivo', 1);

        $this->transporte->falla = true;
        $trabajoId = $this->postJson("/api/impresion/ventas/{$id}")
            ->assertOk()
            ->assertJsonPath('impreso', false)
            ->assertJsonPath('message', 'No se pudo conectar con Mostrador')
            ->json('trabajos.0.id');

        $this->assertSame('error', TrabajoImpresion::find($trabajoId)->estado);

        $this->transporte->falla = false;
        $this->postJson("/api/impresion/trabajos/{$trabajoId}/reintentar")->assertJsonPath('impreso', true);

        $trabajo = TrabajoImpresion::find($trabajoId);
        $this->assertSame('impreso', $trabajo->estado);
        $this->assertSame(2, $trabajo->intentos);
        $this->assertCount(1, $this->transporte->enviados);
    }

    public function test_prueba_de_produccion_imprime_la_comanda_de_ejemplo(): void
    {
        $impresora = $this->impresora();
        $this->actuarComo('admin');

        $this->postJson("/api/admin/impresora/{$impresora->id}/test-produccion")->assertOk()->assertJsonPath('success', true);

        $texto = $this->transporte->texto();
        foreach (['#031719', 'Solicitud de producción', 'Mesa: MESA 1', 'Ticket 1', 'CANT    DESCRIPCIÓN',
            '1       Ice latte', '* saborizante caramelo', '* leche deslactosada', 'ORIGINAL'] as $linea) {
            $this->assertStringContainsString($linea, $texto);
        }
        $this->assertStringNotContainsString('$', $texto);
    }

    public function test_listado_de_trabajos_solo_para_quien_gestiona_impresoras(): void
    {
        $this->actuarComo('cajero');
        $this->getJson('/api/impresion/trabajos')->assertStatus(403);

        $this->actuarComo('admin');
        $this->getJson('/api/impresion/trabajos?estado=error')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_eliminar_impresiones_con_error_una_o_todas(): void
    {
        $trabajo = fn (string $estado) => TrabajoImpresion::create(['tipo' => 'cierre', 'estado' => $estado, 'intentos' => 1,
            'error' => $estado === 'error' ? 'No se pudo conectar' : null, 'contenido' => 'x']);
        [$e1, $e2, $e3, $ok] = [$trabajo('error'), $trabajo('error'), $trabajo('error'), $trabajo('impreso')];

        $this->actuarComo('cajero');
        $this->deleteJson("/api/impresion/trabajos/{$e1->id}")->assertStatus(403);

        $this->actuarComo('admin');
        $this->deleteJson("/api/impresion/trabajos/{$ok->id}")->assertStatus(422); // lo impreso no se borra
        $this->deleteJson("/api/impresion/trabajos/{$e1->id}")->assertOk();
        $this->assertNull(TrabajoImpresion::find($e1->id));

        $this->deleteJson('/api/impresion/trabajos')->assertOk()->assertJsonPath('message', '2 error(es) de impresión eliminado(s)');
        $this->assertSame(0, TrabajoImpresion::where('estado', 'error')->count());
        $this->assertNotNull(TrabajoImpresion::find($ok->id));
        $this->assertDatabaseHas('auditorias', ['accion' => 'eliminar_errores']);
        $this->assertNotNull($e2->id + $e3->id);
    }

    // ── Comandas ────────────────────────────────────────────────

    // Categoría → área de preparación → impresora del área
    private function area(string $nombre, Categoria $categoria, ?Impresora $impresora): void
    {
        $area = AreaPreparacion::where('nombre', $nombre)->first();
        $area->update(['impresora_id' => $impresora?->id]);
        $categoria->update(['area_preparacion_id' => $area->id]);
    }

    public function test_comandas_se_reparten_por_area(): void
    {
        $this->impresora(['predeterminada' => true]);
        $areaCocina = $this->impresora(['alias' => 'Cocina', 'uso' => 'comanda', 'ip_impresora' => '192.168.1.60', 'cash_drawer' => 'ninguno']);
        $barra = $this->impresora(['alias' => 'Barra', 'uso' => 'comanda', 'ip_impresora' => '192.168.1.61', 'cash_drawer' => 'ninguno']);
        $this->area('Cocina', $this->heladas, $areaCocina);
        $this->area('Barra', $this->bebidas, $barra);

        $this->actuarComo('cajero');
        $id = $this->vender([['producto_id' => $this->latteHelado->id, 'cantidad' => 6], ['producto_id' => $this->cafe->id, 'cantidad' => 2]], 'Efectivo', 5);

        $this->postJson("/api/impresion/ventas/{$id}", ['comandas' => true])->assertJsonCount(3, 'trabajos');

        $porImpresora = collect($this->transporte->enviados)->keyBy('impresora');
        $areaCocina = mb_convert_encoding($porImpresora['Cocina']['bytes'], 'UTF-8', 'CP850');
        $barra = mb_convert_encoding($porImpresora['Barra']['bytes'], 'UTF-8', 'CP850');

        $this->assertStringContainsString('THE FROZEN', $areaCocina);
        $this->assertStringContainsString('6       Latte helado', $areaCocina);
        $this->assertStringContainsString('Solicitud de producción', $areaCocina);
        $this->assertStringContainsString('ORIGINAL', $areaCocina);
        $this->assertStringNotContainsString('Café', $areaCocina);
        $this->assertStringContainsString('2       Café con leche', $barra);
        $this->assertStringNotContainsString('$', $barra); // la comanda no lleva precios
        // Cada ticket dice en grande de qué área es
        $this->assertStringContainsString('** COCINA **', $areaCocina);
        $this->assertStringContainsString('** BARRA **', $barra);
    }

    public function test_producto_que_no_imprime_comanda_solo_lleva_el_ticket(): void
    {
        $this->impresora(['predeterminada' => true]);
        $areaCocina = $this->impresora(['alias' => 'Cocina', 'uso' => 'comanda', 'ip_impresora' => '192.168.1.60', 'cash_drawer' => 'ninguno']);
        $barra = $this->impresora(['alias' => 'Barra', 'uso' => 'comanda', 'ip_impresora' => '192.168.1.61', 'cash_drawer' => 'ninguno']);
        $this->area('Cocina', $this->heladas, $areaCocina);
        $this->area('Barra', $this->bebidas, $barra);
        // El café está en Bebidas (área Barra) pero se entrega directo
        $this->cafe->update(['imprime_comanda' => false]);

        $this->actuarComo('cajero');
        $id = $this->vender([['producto_id' => $this->latteHelado->id, 'cantidad' => 6], ['producto_id' => $this->cafe->id, 'cantidad' => 2]], 'Efectivo', 5);
        $this->postJson("/api/impresion/ventas/{$id}", ['comandas' => true])->assertJsonCount(2, 'trabajos'); // ticket + comanda de cocina

        $impresoras = collect($this->transporte->enviados)->pluck('impresora');
        $this->assertContains('Cocina', $impresoras);
        $this->assertNotContains('Barra', $impresoras);
        $ticket = mb_convert_encoding(collect($this->transporte->enviados)->firstWhere('impresora', 'Mostrador')['bytes'], 'UTF-8', 'CP850');
        $this->assertStringContainsString('Café con leche', $ticket); // en el ticket sí sale

        // Sin conexión la tablet tampoco le manda comanda
        $this->getJson("/api/impresion/offline?caja_id={$this->caja->id}")->assertOk()
            ->assertJsonMissingPath("productos.{$this->cafe->id}");
        // Y en el POS ya no dice a qué área va
        $this->assertNull(collect($this->getJson('/api/pos/catalogo')->json('productos'))->firstWhere('id', $this->cafe->id)['area']);
    }

    public function test_en_la_star_el_ticket_de_cobro_sale_con_letra_mas_grande(): void
    {
        $star = $this->impresora(['predeterminada' => true, 'tipo_impresion' => 'star_raster', 'uso' => 'ambos']);
        $epson = $this->impresora(['alias' => 'Epson']);

        $this->actuarComo('cajero');
        $venta = \App\Models\Venta::find($this->vender([['producto_id' => $this->latteHelado->id, 'cantidad' => 2]]));

        // Star: 42 columnas en vez de 48 (marca ESC ~ 42 para StarRaster); ESC/POS: igual que siempre
        $this->assertStringContainsString("\x1B~" . chr(42), \App\Services\ImpresionService::ticket($venta, $star)->bytes());
        $this->assertStringNotContainsString("\x1B~", \App\Services\ImpresionService::ticket($venta, $epson)->bytes());
        // La comanda no cambia
        $comanda = \App\Services\ImpresionService::comanda([['producto_id' => $this->latteHelado->id, 'nombre' => 'Latte', 'cantidad' => 1, 'unidad' => null]], $star);
        $this->assertStringNotContainsString("\x1B~", $comanda->bytes());

        // La Star de 48 columnas lo dibuja igual que una de 42 (letra más grande), no a 48
        $bytes = \App\Services\ImpresionService::ticket($venta, $star)->bytes();
        $sinMarca = str_replace("\x1B~" . chr(42), '', $bytes);
        $this->assertSame(\App\Support\Impresion\StarRaster::desdeEscPos($sinMarca, 42), \App\Support\Impresion\StarRaster::desdeEscPos($bytes, 48));
        $this->assertNotSame(\App\Support\Impresion\StarRaster::desdeEscPos($sinMarca, 48), \App\Support\Impresion\StarRaster::desdeEscPos($bytes, 48));
    }

    public function test_configuracion_para_imprimir_sin_conexion(): void
    {
        $ticket = $this->impresora(['predeterminada' => true, 'tipo_impresion' => 'star_raster']);
        $areaCocina = $this->impresora(['alias' => 'Cocina', 'uso' => 'comanda', 'ip_impresora' => '192.168.1.60', 'cash_drawer' => 'ninguno']);
        $this->area('Cocina', $this->heladas, $areaCocina);

        $this->actuarComo('cajero');
        $res = $this->getJson("/api/impresion/offline?caja_id={$this->caja->id}")->assertOk();

        $res->assertJsonPath('ticket.id', $ticket->id)
            ->assertJsonPath('ticket.ip', '192.168.1.50')
            ->assertJsonPath('ticket.tipo_impresion', 'star_raster')
            ->assertJsonPath('areas.0.nombre', 'Cocina')
            ->assertJsonPath('areas.0.impresora.ip', '192.168.1.60');
        // El latte va a cocina; el café no tiene área (solo lleva el ticket)
        $this->assertSame($res->json('areas.0.id'), $res->json("productos.{$this->latteHelado->id}"));
        $this->assertNull($res->json("productos.{$this->cafe->id}"));
        $this->assertNotEmpty($res->json('negocio.nombre'));
    }

    public function test_cocina_y_barra_en_la_misma_impresora_salen_en_tickets_separados(): void
    {
        $unica = $this->impresora(['predeterminada' => true]);
        $this->area('Cocina', $this->heladas, $unica);
        $this->area('Barra', $this->bebidas, $unica);

        $this->actuarComo('cajero');
        $id = $this->vender([['producto_id' => $this->latteHelado->id, 'cantidad' => 6], ['producto_id' => $this->cafe->id, 'cantidad' => 2]], 'Efectivo', 5);
        $this->postJson("/api/impresion/ventas/{$id}", ['comandas' => true])->assertJsonCount(3, 'trabajos'); // ticket + 2 comandas

        $comandas = collect($this->transporte->enviados)->map(fn ($e) => mb_convert_encoding($e['bytes'], 'UTF-8', 'CP850'))
            ->filter(fn ($t) => str_contains($t, 'Solicitud de producción'))->values();
        $this->assertCount(2, $comandas);
        $cocina = $comandas->first(fn ($t) => str_contains($t, '** COCINA **'));
        $barra = $comandas->first(fn ($t) => str_contains($t, '** BARRA **'));
        $this->assertNotNull($cocina);
        $this->assertNotNull($barra);
        $this->assertStringContainsString('Latte helado', $cocina);
        $this->assertStringNotContainsString('Café', $cocina);
        $this->assertStringContainsString('Café con leche', $barra);
        $this->assertStringNotContainsString('Latte helado', $barra);
    }

    public function test_venta_de_mostrador_sin_area_solo_imprime_el_ticket(): void
    {
        $this->impresora(['predeterminada' => true]); // solo la de tickets
        $this->actuarComo('cajero');
        $id = $this->vender([['producto_id' => $this->latteHelado->id, 'cantidad' => 6]], 'Efectivo', 1);

        // El postre de vitrina se entrega directo: sale el ticket de venta y ninguna comanda
        $this->postJson("/api/impresion/ventas/{$id}", ['comandas' => true])->assertJsonCount(1, 'trabajos');
        $this->assertStringNotContainsString('Solicitud de producción', $this->transporte->texto(0));
    }

    public function test_comandas_de_precuenta_con_notas(): void
    {
        $areaCocina = $this->impresora(['alias' => 'Cocina', 'uso' => 'comanda', 'cash_drawer' => 'ninguno']);
        $this->area('Cocina', $this->heladas, $areaCocina);
        $this->actuarComo('cajero');

        $pedidoId = $this->postJson('/api/pos/pedidos', [
            'uuid' => (string) Str::uuid(), 'nombre' => 'Encargo Sra. Ana',
            'items' => [['producto_id' => $this->latteHelado->id, 'cantidad' => 30, 'notas' => 'bien tostado']],
        ])->json('pedido.id');

        $this->postJson("/api/impresion/pedidos/{$pedidoId}/comandas")->assertJsonPath('impreso', true);

        $texto = $this->transporte->texto();
        $this->assertStringContainsString('Encargo Sra. Ana', $texto);
        $this->assertStringContainsString('* bien tostado', $texto);
    }

    // ── Cierre, prueba y cajón ──────────────────────────────────

    public function test_cierre_de_caja_impreso(): void
    {
        $this->impresora(['predeterminada' => true]);
        $usuario = $this->actuarComo('admin');
        CajaService::cerrar($this->turno, ['10' => 1, '5' => 1, '1' => 4], null, $usuario->id);

        $this->postJson("/api/impresion/turnos/{$this->turno->id}")->assertJsonPath('impreso', true);

        $texto = $this->transporte->texto();
        $this->assertStringContainsString('CIERRE DE CAJA', $texto);
        $this->assertStringContainsString('FALTANTE', $texto);
        $this->assertStringContainsString('$1.00', $texto);
    }

    public function test_corte_parcial_con_cierre_ciego_no_muestra_el_esperado(): void
    {
        $this->impresora(['predeterminada' => true]);
        \App\Models\ConfiguracionNegocio::actual()->update(['cierre_ciego' => true]);

        // El cajero (sin historial de cierres) imprime el corte con el turno abierto
        $this->actuarComo('cajero');
        $this->postJson("/api/impresion/turnos/{$this->turno->id}")->assertJsonPath('impreso', true);
        $texto = $this->transporte->texto(0);
        $this->assertStringContainsString('CORTE PARCIAL', $texto);
        $this->assertStringContainsString('Efectivo esperado: oculto (cierre ciego)', $texto);

        // El admin sí lo ve
        $this->actuarComo('admin');
        $this->postJson("/api/impresion/turnos/{$this->turno->id}")->assertJsonPath('impreso', true);
        $this->assertStringNotContainsString('oculto', $this->transporte->texto(1));
        $this->assertMatchesRegularExpression('/Efectivo esperado\s+\$20\.00/', $this->transporte->texto(1));
    }

    public function test_boton_de_prueba_muestra_el_error_de_conexion(): void
    {
        $impresora = $this->impresora();
        $this->actuarComo('admin');

        $this->postJson("/api/admin/impresora/{$impresora->id}/test")->assertOk()->assertJsonPath('success', true);
        $this->assertStringContainsString('PRUEBA DE IMPRESIÓN', $this->transporte->texto());

        $this->transporte->falla = true;
        $this->postJson("/api/admin/impresora/{$impresora->id}/test")
            ->assertStatus(422)->assertJsonPath('message', 'No se pudo conectar con Mostrador');
    }

    public function test_abrir_cajon_exige_motivo_y_queda_auditado(): void
    {
        $this->impresora(['predeterminada' => true]);
        $this->actuarComo('admin');

        $this->postJson('/api/impresion/cajon', ['caja_id' => $this->caja->id])->assertStatus(422);
        $this->postJson('/api/impresion/cajon', ['caja_id' => $this->caja->id, 'motivo' => 'Dar cambio'])->assertJsonPath('impreso', true);

        $this->assertSame("\x1Bp\x00\x19\xFA", substr($this->transporte->enviados[0]['bytes'], -5));
        $this->assertDatabaseHas('auditorias', ['accion' => 'abrir_cajon', 'descripcion' => 'Dar cambio']);
    }

    public function test_sin_el_permiso_el_cajon_se_abre_con_el_pin_del_administrador(): void
    {
        $this->impresora(['predeterminada' => true]);
        $this->crearUsuario('admin', ['nombre' => 'Gerente', 'pin' => '4321', 'pin_actualizado_at' => now()]);
        $mesero = $this->actuarComo('mesero', ['nombre' => 'Ana']);
        $this->assertFalse($mesero->can('caja.abrir_cajon'), 'El mesero no lo trae de entrada');

        $datos = ['caja_id' => $this->caja->id, 'motivo' => 'Cliente pagó un billete de $20 por otra cosa'];
        $this->postJson('/api/impresion/cajon', $datos)->assertStatus(403)->assertJsonPath('status', 'SIN_PERMISO');
        $this->assertCount(0, $this->transporte->enviados);
        $this->postJson('/api/impresion/cajon', [...$datos, 'autorizacion' => '0000'])->assertStatus(403);
        $this->postJson('/api/impresion/cajon', [...$datos, 'autorizacion' => '4321'])->assertOk()->assertJsonPath('impreso', true);

        $registro = \App\Models\Auditoria::where('accion', 'abrir_cajon')->latest('id')->first();
        $this->assertSame('Cliente pagó un billete de $20 por otra cosa', $registro->descripcion);
        $this->assertSame('Ana', $registro->datos_despues['abrio']);
        $this->assertSame('Gerente', $registro->datos_despues['autorizado_por']);
        $this->assertSame($this->turno->id, $registro->datos_despues['turno_id']);
    }

    public function test_sin_turno_abierto_no_se_abre_el_cajon(): void
    {
        $this->impresora(['predeterminada' => true]);
        $this->actuarComo('admin');
        $this->turno->update(['estado' => 'cerrado']);

        $this->postJson('/api/impresion/cajon', ['caja_id' => $this->caja->id, 'motivo' => 'x'])->assertStatus(422)
            ->assertJsonPath('message', 'Abre el turno de esta caja antes de abrir el cajón');
    }

    // ── Configuración ───────────────────────────────────────────

    public function test_validaciones_de_impresora_y_una_sola_predeterminada(): void
    {
        $this->actuarComo('admin');
        $base = ['alias' => 'Nueva', 'conexion' => 'red', 'ancho_caracteres' => 48, 'uso' => 'ticket', 'cash_drawer' => 'ninguno'];

        $this->postJson('/api/admin/impresora/store', $base)->assertStatus(422)->assertJsonValidationErrors('ip_impresora');
        $this->postJson('/api/admin/impresora/store', $base + ['ip_impresora' => '300.1.1.1'])->assertStatus(422);
        $this->postJson('/api/admin/impresora/store', ['conexion' => 'windows'] + $base)->assertStatus(422)->assertJsonValidationErrors('nombre_windows');

        $a = $this->postJson('/api/admin/impresora/store', $base + ['ip_impresora' => '192.168.1.80', 'predeterminada' => true])->assertCreated()->json('data.id');
        $b = $this->postJson('/api/admin/impresora/store', ['alias' => 'Otra', 'ip_impresora' => '192.168.1.81', 'predeterminada' => true] + $base)->assertCreated()->json('data.id');

        $this->assertFalse(Impresora::find($a)->predeterminada);
        $this->assertTrue(Impresora::find($b)->predeterminada);
        $this->assertSame(9100, Impresora::find($b)->puerto);
    }

    // ── ESC/POS ─────────────────────────────────────────────────

    public function test_escpos_alinea_columnas_y_codifica_acentos(): void
    {
        $doc = (new EscPos(32))->columnas('Total', '$12.50');
        $linea = substr($doc->bytes(), 5); // sin la inicialización (ESC @ ESC t 2)

        $this->assertSame(33, strlen($linea)); // 32 caracteres + salto
        $this->assertStringEndsWith("\$12.50\n", $linea);

        $this->assertSame("\xA4", EscPos::codificar('ñ'));   // ñ en CP850
        $this->assertSame("\xA0", EscPos::codificar('á'));
    }
}
