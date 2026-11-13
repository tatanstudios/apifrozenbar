<?php

namespace App\Services;

use App\Exceptions\NegocioException;
use App\Models\Administrador;
use App\Models\CategoriaGasto;
use App\Models\Gasto;
use App\Models\MetodoPago;
use App\Models\Proveedor;
use App\Models\TurnoCaja;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Gastos del negocio (luz, gas, planilla, mantenimiento...). El que se paga
 * desde la caja es un movimiento "gasto" del turno abierto.
 */
class GastoService
{
    // Disco privado: los comprobantes solo se descargan con sesión y permiso
    const DISCO = 'local';

    /**
     * $datos: uuid, fecha, categoria_id, proveedor_id?, descripcion, monto, origen (caja|externo),
     *         caja_id?, metodo_pago_id?, referencia?, numero_documento?
     */
    public static function registrar(array $datos, Administrador $usuario, ?UploadedFile $comprobante = null): Gasto
    {
        if ($existente = Gasto::where('uuid', $datos['uuid'])->first()) {
            return $existente;
        }

        $fecha = Carbon::parse($datos['fecha'] ?? today());
        if ($fecha->gt(today())) {
            throw new NegocioException('La fecha del gasto no puede ser futura');
        }

        $categoria = CategoriaGasto::find($datos['categoria_id']);
        if (!$categoria || !$categoria->activo) {
            throw new NegocioException('La categoría no existe o está desactivada');
        }
        if (!empty($datos['proveedor_id']) && !Proveedor::whereKey($datos['proveedor_id'])->exists()) {
            throw new NegocioException('El proveedor no existe');
        }

        $monto = round((float) $datos['monto'], 2);
        if ($monto <= 0) {
            throw new NegocioException('El monto debe ser mayor a 0');
        }

        $metodo = !empty($datos['metodo_pago_id']) ? MetodoPago::find($datos['metodo_pago_id']) : MetodoPago::efectivo();
        if (!$metodo || !$metodo->activo || $metodo->tipo === 'credito') {
            throw new NegocioException('Forma de pago no válida para un gasto');
        }
        if ($metodo->requiere_referencia && empty($datos['referencia'])) {
            throw new NegocioException("{$metodo->nombre} requiere número de referencia");
        }

        // El archivo se guarda antes; si la transacción falla se borra
        $ruta = $comprobante?->store('comprobantes/gastos/' . $fecha->format('Y-m'), self::DISCO);

        try {
            return DB::transaction(function () use ($datos, $usuario, $fecha, $categoria, $monto, $metodo, $ruta) {
                $turno = null;
                if (($datos['origen'] ?? 'caja') === 'caja') {
                    $turno = TurnoCaja::where('caja_id', $datos['caja_id'] ?? 0)->where('estado', 'abierto')->first();
                    if (!$turno) {
                        throw new NegocioException('Abre el turno de caja para pagar el gasto desde la caja');
                    }
                    CajaService::exigirTurnoPropio($turno);
                }

                $gasto = Gasto::create([
                    'uuid'             => $datos['uuid'],
                    'numero'           => CorrelativoService::siguiente('gasto', 'G'),
                    'fecha'            => $fecha->toDateString(),
                    'categoria_id'     => $categoria->id,
                    'proveedor_id'     => $datos['proveedor_id'] ?? null,
                    'descripcion'      => $datos['descripcion'],
                    'monto'            => $monto,
                    'origen'           => $turno ? 'caja' : 'externo',
                    'turno_id'         => $turno?->id,
                    'metodo_pago_id'   => $metodo->id,
                    'referencia'       => $datos['referencia'] ?? null,
                    'numero_documento' => $datos['numero_documento'] ?? null,
                    'comprobante'      => $ruta,
                    'usuario_id'       => $usuario->id,
                ]);

                if ($turno) {
                    CajaService::registrarMovimiento(
                        turno:         $turno,
                        tipo:          'gasto',
                        monto:         $monto,
                        concepto:      "Gasto {$gasto->numero}: {$categoria->nombre} · {$gasto->descripcion}",
                        usuarioId:     $usuario->id,
                        metodoPagoId:  $metodo->id,
                        referencia:    $datos['referencia'] ?? null,
                        documentoTipo: 'gasto',
                        documentoId:   $gasto->id,
                    );
                }

                AuditoriaService::registrar('gastos', 'registrar', $gasto, null, [
                    'categoria' => $categoria->nombre,
                    'monto'     => $monto,
                    'origen'    => $gasto->origen,
                    'metodo'    => $metodo->nombre,
                ], $gasto->descripcion);

                return $gasto;
            });
        } catch (\Throwable $e) {
            if ($ruta) {
                Storage::disk(self::DISCO)->delete($ruta);
            }
            if ($e instanceof UniqueConstraintViolationException && ($existente = Gasto::where('uuid', $datos['uuid'])->first())) {
                return $existente;
            }
            throw $e;
        }
    }

    /**
     * Anula un gasto. Si salió de la caja, su turno debe seguir abierto y el
     * dinero vuelve a la gaveta.
     */
    public static function anular(Gasto $gasto, string $motivo, Administrador $usuario): Gasto
    {
        $autorizador = AutorizacionService::autorizar($usuario, 'pos.anular', 'Anular un gasto necesita autorización del administrador');

        return DB::transaction(function () use ($gasto, $motivo, $usuario, $autorizador) {
            $gasto = Gasto::whereKey($gasto->id)->lockForUpdate()->first();
            if ($gasto->anulado) {
                throw new NegocioException('El gasto ya está anulado');
            }

            if ($gasto->origen === 'caja') {
                $turno = TurnoCaja::find($gasto->turno_id);
                if (!$turno?->estaAbierto()) {
                    throw new NegocioException('El gasto salió de un turno de caja ya cerrado: no se puede anular');
                }
                CajaService::anularPorDocumento($turno, 'gasto', $gasto->id, "Gasto {$gasto->numero} anulado: {$motivo}", $usuario->id);
            }

            $gasto->update(['anulado' => true, 'anulado_por' => $usuario->id, 'anulado_at' => now(), 'motivo_anulacion' => $motivo]);

            AuditoriaService::registrar('gastos', 'anular', $gasto,
                ['monto' => (float) $gasto->monto],
                ['anulado' => true, 'autorizado_por' => $autorizador->nombre],
                $motivo);

            return $gasto;
        });
    }

    /**
     * Gastos del periodo por categoría, por mes y por forma de pago.
     */
    public static function resumen(Carbon $desde, Carbon $hasta): array
    {
        $gastos = Gasto::with(['categoria:id,nombre', 'metodoPago:id,nombre'])
            ->where('anulado', false)
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->get();
        $total = (float) $gastos->sum('monto');

        return [
            'desde'          => $desde->toDateString(),
            'hasta'          => $hasta->toDateString(),
            'total'          => round($total, 2),
            'cantidad'       => $gastos->count(),
            'desde_caja'     => round((float) $gastos->where('origen', 'caja')->sum('monto'), 2),
            'fuera_de_caja'  => round((float) $gastos->where('origen', 'externo')->sum('monto'), 2),
            'por_categoria'  => $gastos->groupBy('categoria_id')->map(fn ($g) => [
                'categoria_id' => $g->first()->categoria_id,
                'categoria'    => $g->first()->categoria?->nombre,
                'cantidad'     => $g->count(),
                'total'        => round((float) $g->sum('monto'), 2),
                'porcentaje'   => $total > 0 ? round((float) $g->sum('monto') / $total * 100, 1) : 0,
            ])->sortByDesc('total')->values()->all(),
            'por_mes'        => $gastos->groupBy(fn ($g) => $g->fecha->format('Y-m'))->map(fn ($g, $mes) => [
                'mes' => $mes, 'cantidad' => $g->count(), 'total' => round((float) $g->sum('monto'), 2),
            ])->sortKeys()->values()->all(),
            'por_metodo'     => $gastos->groupBy('metodo_pago_id')->map(fn ($g) => [
                'metodo' => $g->first()->metodoPago?->nombre, 'total' => round((float) $g->sum('monto'), 2),
            ])->sortByDesc('total')->values()->all(),
        ];
    }
}
