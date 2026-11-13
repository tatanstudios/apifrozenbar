<?php

namespace App\Services;

use App\Exceptions\NegocioException;
use App\Models\ArqueoCaja;
use App\Models\Caja;
use App\Models\ConfiguracionNegocio;
use App\Models\MetodoPago;
use App\Models\MovimientoCaja;
use App\Models\MovimientoInventario;
use App\Models\TurnoCaja;
use App\Models\Venta;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CajaService
{
    /**
     * El dinero que entra o sale de la caja va al turno de quien tiene la sesión.
     * Si la caja tiene abierto el turno de otro (se fue sin cerrarlo), primero hay
     * que cerrarlo: así no se mezclan ventas ni efectivo de dos personas.
     * Cerrar y hacer arqueo sí se permite, es la forma de salir de ese estado.
     * El admin, y quien tenga «caja.turno_compartido» (varios meseros con su tablet
     * cobrando en el turno de quien abrió la caja), pueden operar en cualquier turno.
     */
    public static function exigirTurnoPropio(TurnoCaja $turno): void
    {
        if (self::puedeOperarEn($turno, auth()->user())) {
            return;
        }

        $de = $turno->usuarioApertura?->nombre ?? 'otro usuario';
        throw new NegocioException("La caja tiene abierto el turno de {$de}. Ciérralo y abre el tuyo para seguir.");
    }

    /** Cierre ciego: quien no ve el historial de cierres no debe saber cuánto efectivo hay en caja. */
    public static function ocultarEsperado(): bool
    {
        return ConfiguracionNegocio::actual()->cierre_ciego && !auth()->user()?->can('caja.historial');
    }

    /** Si el usuario puede vender y registrar dinero en ese turno (el suyo, o el de otro con permiso). */
    public static function puedeOperarEn(TurnoCaja $turno, $usuario): bool
    {
        if (!$usuario || (int) $turno->usuario_apertura_id === (int) $usuario->id) {
            return true;
        }

        // can() pasa siempre para el admin (Gate::before)
        return method_exists($usuario, 'can') && $usuario->can('caja.turno_compartido');
    }

    /**
     * Efectivo que quedó en la gaveta al cerrar el último turno de la caja (lo contado).
     * El dinero no se retira al cerrar: es el fondo con el que abre el siguiente turno.
     * null si la caja nunca tuvo un turno cerrado.
     */
    public static function efectivoQueQuedo(int $cajaId): ?float
    {
        $anterior = TurnoCaja::where('caja_id', $cajaId)->where('estado', 'cerrado')
            ->latest('cerrado_at')->latest('id')->first();

        return $anterior && $anterior->efectivo_contado !== null ? round((float) $anterior->efectivo_contado, 2) : null;
    }

    /**
     * Abre un turno en la caja. Bloquea la caja para que dos dispositivos no
     * abran a la vez; la base de datos además impide dos turnos abiertos por caja.
     */
    public static function abrir(int $cajaId, float $fondoInicial, int $usuarioId, ?string $observaciones = null): TurnoCaja
    {
        return DB::transaction(function () use ($cajaId, $fondoInicial, $usuarioId, $observaciones) {
            $caja = Caja::whereKey($cajaId)->lockForUpdate()->first();

            if (!$caja || !$caja->activo) {
                throw new NegocioException('La caja no existe o está desactivada');
            }

            if (TurnoCaja::where('caja_id', $cajaId)->where('estado', 'abierto')->exists()) {
                throw new NegocioException("{$caja->nombre} ya tiene un turno abierto");
            }

            return TurnoCaja::create([
                'caja_id'                => $cajaId,
                'usuario_apertura_id'    => $usuarioId,
                'estado'                 => 'abierto',
                'abierto_at'             => now(),
                'fondo_inicial'          => round($fondoInicial, 2),
                'observaciones_apertura' => $observaciones,
            ]);
        });
    }

    /**
     * Registra dinero que entra o sale de la caja. La usan la pantalla de caja
     * (ingresos/egresos) y, más adelante, ventas, devoluciones, abonos y gastos.
     *
     * Es idempotente por uuid: si ya existe, devuelve el registro existente.
     * Debe llamarse dentro de una transacción si forma parte de otra operación.
     */
    public static function registrarMovimiento(
        TurnoCaja $turno,
        string    $tipo,
        float     $monto,
        string    $concepto,
        int       $usuarioId,
        ?int      $metodoPagoId = null,
        ?string   $referencia = null,
        ?string   $documentoTipo = null,
        ?int      $documentoId = null,
        ?string   $uuid = null,
    ): MovimientoCaja {
        return DB::transaction(function () use (
            $turno, $tipo, $monto, $concepto, $usuarioId, $metodoPagoId, $referencia, $documentoTipo, $documentoId, $uuid
        ) {
            if ($uuid && ($existente = MovimientoCaja::where('uuid', $uuid)->first())) {
                return $existente;
            }

            // Bloquear el turno: evita registrar mientras otro dispositivo lo está cerrando
            $turno = TurnoCaja::whereKey($turno->id)->lockForUpdate()->first();

            if (!$turno->estaAbierto()) {
                throw new NegocioException('El turno de caja está cerrado');
            }

            if (!array_key_exists($tipo, MovimientoCaja::TIPOS)) {
                throw new NegocioException("Tipo de movimiento de caja no válido: {$tipo}");
            }

            if ($monto <= 0) {
                throw new NegocioException('El monto debe ser mayor a 0');
            }

            $metodo = $metodoPagoId ? MetodoPago::find($metodoPagoId) : MetodoPago::efectivo();

            if (!$metodo || !$metodo->activo) {
                throw new NegocioException('El método de pago no existe o está desactivado');
            }

            if ($metodo->requiere_referencia && !$referencia) {
                throw new NegocioException("{$metodo->nombre} requiere número de referencia");
            }

            // No se puede sacar más efectivo del que debería haber en la gaveta
            if (in_array($tipo, MovimientoCaja::TIPOS_SALIDA, true) && $metodo->afecta_efectivo) {
                $disponible = self::resumen($turno)['efectivo_esperado'];
                if (round($monto, 2) > round($disponible, 2)) {
                    // Con cierre ciego no se revela cuánto hay (lo ve solo quien ve el historial de cierres)
                    throw new NegocioException(self::ocultarEsperado()
                        ? 'No hay suficiente efectivo en caja para sacar ese monto. Págalo desde otra cuenta o registra antes un ingreso.'
                        : 'No hay suficiente efectivo en caja: disponible $' . number_format($disponible, 2));
                }
            }

            return MovimientoCaja::create([
                'uuid'           => $uuid ?: (string) Str::uuid(),
                'turno_id'       => $turno->id,
                'tipo'           => $tipo,
                'metodo_pago_id' => $metodo->id,
                'monto'          => round($monto, 2),
                'concepto'       => $concepto,
                'referencia'     => $referencia,
                'documento_tipo' => $documentoTipo,
                'documento_id'   => $documentoId,
                'usuario_id'     => $usuarioId,
            ]);
        });
    }

    /**
     * Anula un ingreso o egreso manual (los de ventas se revierten desde su documento).
     */
    public static function anularMovimiento(MovimientoCaja $movimiento, string $motivo, int $usuarioId): MovimientoCaja
    {
        return DB::transaction(function () use ($movimiento, $motivo, $usuarioId) {
            $turno = TurnoCaja::whereKey($movimiento->turno_id)->lockForUpdate()->first();
            $movimiento = MovimientoCaja::whereKey($movimiento->id)->lockForUpdate()->first();

            if (!$turno->estaAbierto()) {
                throw new NegocioException('No se puede anular: el turno ya está cerrado');
            }
            if ($movimiento->anulado) {
                throw new NegocioException('El movimiento ya estaba anulado');
            }
            if (!in_array($movimiento->tipo, MovimientoCaja::TIPOS_MANUALES, true)) {
                throw new NegocioException('Este movimiento se corrige desde su documento (venta, devolución, abono o gasto)');
            }

            // Anular un ingreso en efectivo saca dinero: no puede dejar la gaveta en negativo
            if ($movimiento->esEntrada() && $movimiento->metodoPago->afecta_efectivo) {
                $disponible = self::resumen($turno)['efectivo_esperado'];
                if ((float) $movimiento->monto > $disponible + 0.001) {
                    throw new NegocioException('No se puede anular: el efectivo en caja quedaría negativo');
                }
            }

            $movimiento->update([
                'anulado'          => true,
                'anulado_por'      => $usuarioId,
                'anulado_at'       => now(),
                'motivo_anulacion' => $motivo,
            ]);

            return $movimiento;
        });
    }

    /**
     * Anula los movimientos de caja de un documento (ej. una venta anulada).
     * El documento ya validó su propia regla; aquí solo se cuida que el turno siga
     * abierto y que la gaveta no quede con efectivo negativo.
     * Debe llamarse dentro de una transacción.
     */
    public static function anularPorDocumento(TurnoCaja $turno, string $documentoTipo, int $documentoId, string $motivo, int $usuarioId): void
    {
        $turno = TurnoCaja::whereKey($turno->id)->lockForUpdate()->first();

        if (!$turno->estaAbierto()) {
            throw new NegocioException('El turno de caja de este documento ya está cerrado');
        }

        $movimientos = MovimientoCaja::with('metodoPago')
            ->where('turno_id', $turno->id)
            ->where('documento_tipo', $documentoTipo)
            ->where('documento_id', $documentoId)
            ->where('anulado', false)
            ->lockForUpdate()
            ->get();

        $efectivoQueSale = $movimientos
            ->filter(fn ($m) => $m->esEntrada() && $m->metodoPago->afecta_efectivo)
            ->sum('monto');

        if ($efectivoQueSale > self::resumen($turno)['efectivo_esperado'] + 0.001) {
            throw new NegocioException('No hay suficiente efectivo en caja para revertir este documento');
        }

        foreach ($movimientos as $m) {
            $m->update([
                'anulado'          => true,
                'anulado_por'      => $usuarioId,
                'anulado_at'       => now(),
                'motivo_anulacion' => $motivo,
            ]);
        }
    }

    /**
     * Total de un conteo por denominaciones, calculado en el servidor.
     * $denominaciones = ['20' => 3, '0.25' => 8, ...]
     */
    public static function totalDenominaciones(array $denominaciones): float
    {
        $validas = collect(config('caja.denominaciones'))->pluck('valor')->all();
        $total = 0;

        foreach ($denominaciones as $valor => $cantidad) {
            if (!in_array((string) $valor, $validas, true)) {
                throw new NegocioException("Denominación no válida: {$valor}");
            }
            if (!is_numeric($cantidad) || $cantidad < 0 || floor($cantidad) != $cantidad) {
                throw new NegocioException("La cantidad de {$valor} debe ser un número entero");
            }
            // En centavos para no acumular errores de punto flotante
            $total += (int) round(((float) $valor) * 100) * (int) $cantidad;
        }

        return $total / 100;
    }

    /**
     * Arqueo parcial: cuenta la gaveta sin cerrar el turno.
     */
    public static function arqueo(TurnoCaja $turno, ?array $denominaciones, ?float $totalContado, int $usuarioId, ?string $observaciones = null): ArqueoCaja
    {
        return DB::transaction(function () use ($turno, $denominaciones, $totalContado, $usuarioId, $observaciones) {
            $turno = TurnoCaja::whereKey($turno->id)->lockForUpdate()->first();

            if (!$turno->estaAbierto()) {
                throw new NegocioException('El turno de caja está cerrado');
            }

            return self::crearArqueo($turno, 'parcial', $denominaciones, $totalContado, $usuarioId, $observaciones);
        });
    }

    /**
     * Cierra el turno: cuenta el efectivo, calcula la diferencia contra lo esperado
     * y guarda una foto de los totales para el historial.
     */
    public static function cerrar(TurnoCaja $turno, ?array $denominaciones, ?float $totalContado, int $usuarioId, ?string $observaciones = null): TurnoCaja
    {
        return DB::transaction(function () use ($turno, $denominaciones, $totalContado, $usuarioId, $observaciones) {
            $turno = TurnoCaja::whereKey($turno->id)->lockForUpdate()->first();

            if (!$turno->estaAbierto()) {
                throw new NegocioException('El turno ya está cerrado');
            }

            $arqueo  = self::crearArqueo($turno, 'cierre', $denominaciones, $totalContado, $usuarioId, $observaciones);
            $resumen = self::resumen($turno);

            $turno->update([
                'estado'               => 'cerrado',
                'cerrado_at'           => now(),
                'usuario_cierre_id'    => $usuarioId,
                'total_ventas'         => $resumen['totales']['ventas'],
                'total_ingresos'       => $resumen['totales']['ingresos'],
                'total_egresos'        => $resumen['totales']['egresos'],
                'efectivo_esperado'    => $arqueo->efectivo_esperado,
                'efectivo_contado'     => $arqueo->total_contado,
                'diferencia'           => $arqueo->diferencia,
                'resumen'              => [
                    'por_metodo'         => $resumen['por_metodo'],
                    'por_tipo'           => $resumen['por_tipo'],
                    'ventas_por_usuario' => $resumen['ventas_por_usuario'],
                    'consumo'            => $resumen['consumo'],
                    'por_comprar'        => $resumen['por_comprar'],
                ],
                'observaciones_cierre' => $observaciones,
            ]);

            return $turno;
        });
    }

    /**
     * Reabre un turno cerrado (corrección excepcional, solo con permiso caja.reabrir).
     */
    public static function reabrir(TurnoCaja $turno): TurnoCaja
    {
        return DB::transaction(function () use ($turno) {
            $turno = TurnoCaja::whereKey($turno->id)->lockForUpdate()->first();

            if ($turno->estaAbierto()) {
                throw new NegocioException('El turno ya está abierto');
            }

            Caja::whereKey($turno->caja_id)->lockForUpdate()->first();
            if (TurnoCaja::where('caja_id', $turno->caja_id)->where('estado', 'abierto')->exists()) {
                throw new NegocioException('La caja ya tiene otro turno abierto: ciérralo primero');
            }

            $turno->update([
                'estado'            => 'abierto',
                'cerrado_at'        => null,
                'usuario_cierre_id' => null,
                'efectivo_esperado' => null,
                'efectivo_contado'  => null,
                'diferencia'        => null,
                'resumen'           => null,
            ]);

            return $turno;
        });
    }

    /**
     * Totales del turno calculados desde sus movimientos vigentes (no anulados).
     */
    public static function resumen(TurnoCaja $turno): array
    {
        $movimientos = MovimientoCaja::with(['metodoPago', 'usuario:id,nombre'])
            ->where('turno_id', $turno->id)
            ->where('anulado', false)
            ->get();

        $porTipo = [];
        foreach (array_keys(MovimientoCaja::TIPOS) as $tipo) {
            $porTipo[$tipo] = round((float) $movimientos->where('tipo', $tipo)->sum('monto'), 2);
        }

        // Por método de pago: siempre aparece el efectivo, aunque no tenga movimientos
        $metodos = MetodoPago::whereIn('id', $movimientos->pluck('metodo_pago_id')->unique())
            ->orWhere(fn ($q) => $q->where('tipo', 'efectivo')->where('activo', true))
            ->orderBy('orden')
            ->get();

        $porMetodo = $metodos->map(function (MetodoPago $m) use ($movimientos) {
            $delMetodo = $movimientos->where('metodo_pago_id', $m->id);
            $entradas  = (float) $delMetodo->filter(fn ($mov) => $mov->esEntrada())->sum('monto');
            $salidas   = (float) $delMetodo->reject(fn ($mov) => $mov->esEntrada())->sum('monto');

            return [
                'metodo_pago_id'  => $m->id,
                'nombre'          => $m->nombre,
                'tipo'            => $m->tipo,
                'afecta_efectivo' => $m->afecta_efectivo,
                'entradas'        => round($entradas, 2),
                'salidas'         => round($salidas, 2),
                'neto'            => round($entradas - $salidas, 2),
            ];
        })->values();

        $efectivoEsperado = (float) $turno->fondo_inicial
            + $porMetodo->where('afecta_efectivo', true)->sum('neto');

        $ventasPorUsuario = $movimientos->where('tipo', 'venta')
            ->groupBy('usuario_id')
            ->map(fn ($grupo) => [
                'usuario_id' => $grupo->first()->usuario_id,
                'usuario'    => $grupo->first()->usuario?->nombre,
                'cantidad'   => $grupo->count(),
                'total'      => round((float) $grupo->sum('monto'), 2),
            ])
            ->sortByDesc('total')
            ->values();

        return [
            'fondo_inicial'      => (float) $turno->fondo_inicial,
            'consumo'            => self::consumoDelTurno($turno),
            // Un turno cerrado conserva la lista de compras que dejó; uno abierto la calcula en vivo
            'por_comprar'        => !$turno->estaAbierto() && isset($turno->resumen['por_comprar'])
                ? $turno->resumen['por_comprar']
                : InventarioService::productosEnAlerta(),
            // Datos del negocio: si el ticket de cierre imprime «POR COMPRAR» (la pantalla la muestra siempre)
            'ticket_por_comprar' => (bool) ConfiguracionNegocio::actual()->ticket_por_comprar,
            'efectivo_esperado'  => round($efectivoEsperado, 2),
            'por_tipo'           => $porTipo,
            'por_metodo'         => $porMetodo->all(),
            'ventas_por_usuario' => $ventasPorUsuario->all(),
            'totales'            => [
                'ventas'       => $porTipo['venta'],
                'devoluciones' => $porTipo['devolucion'],
                'ingresos'     => round($porTipo['ingreso'] + $porTipo['abono'] + $porTipo['anticipo'], 2),
                'egresos'      => round($porTipo['egreso'] + $porTipo['gasto'] + $porTipo['pago_proveedor'], 2),
            ],
        ];
    }

    /**
     * Lo que salió del inventario con las ventas del turno (los insumos de las recetas
     * incluidos), separando lo vendido del consumo interno. Las ventas anuladas no cuentan.
     */
    public static function consumoDelTurno(TurnoCaja $turno): array
    {
        $ventas = Venta::where('turno_id', $turno->id)->where('estado', '!=', 'anulada')->select('id');

        return MovimientoInventario::query()
            ->join('productos', 'productos.id', '=', 'movimientos_inventario.producto_id')
            ->leftJoin('unidades_medida', 'unidades_medida.id', '=', 'productos.unidad_medida_id')
            ->where('movimientos_inventario.referencia_tipo', 'venta')
            ->whereIn('movimientos_inventario.referencia_id', $ventas)
            ->whereIn('movimientos_inventario.tipo', ['venta', 'consumo_interno'])
            ->groupBy('productos.id', 'productos.nombre', 'unidades_medida.abreviatura')
            ->orderBy('productos.nombre')
            ->get([
                'productos.id as producto_id',
                'productos.nombre as producto',
                'unidades_medida.abreviatura as unidad',
                DB::raw("SUM(CASE WHEN movimientos_inventario.tipo = 'venta' THEN movimientos_inventario.cantidad ELSE 0 END) as vendido"),
                DB::raw("SUM(CASE WHEN movimientos_inventario.tipo = 'consumo_interno' THEN movimientos_inventario.cantidad ELSE 0 END) as consumo_interno"),
                DB::raw('SUM(movimientos_inventario.costo_total) as costo'),
            ])
            ->map(fn ($m) => [
                'producto_id'     => $m->producto_id,
                'producto'        => $m->producto,
                'unidad'          => $m->unidad,
                'vendido'         => round((float) $m->vendido, 4),
                'consumo_interno' => round((float) $m->consumo_interno, 4),
                'costo'           => round((float) $m->costo, 2),
            ])
            ->all();
    }

    private static function crearArqueo(TurnoCaja $turno, string $tipo, ?array $denominaciones, ?float $totalContado, int $usuarioId, ?string $observaciones): ArqueoCaja
    {
        if ($denominaciones) {
            $denominaciones = array_filter($denominaciones, fn ($c) => (int) $c > 0);
            $contado = self::totalDenominaciones($denominaciones);
        } elseif ($totalContado !== null) {
            $contado = round($totalContado, 2);
        } else {
            throw new NegocioException('Indica el efectivo contado');
        }

        $esperado = self::resumen($turno)['efectivo_esperado'];

        return ArqueoCaja::create([
            'turno_id'          => $turno->id,
            'tipo'              => $tipo,
            'denominaciones'    => $denominaciones ?: null,
            'total_contado'     => $contado,
            'efectivo_esperado' => $esperado,
            'diferencia'        => round($contado - $esperado, 2),
            'observaciones'     => $observaciones,
            'usuario_id'        => $usuarioId,
        ]);
    }
}
