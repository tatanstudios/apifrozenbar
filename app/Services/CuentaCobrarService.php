<?php

namespace App\Services;

use App\Exceptions\NegocioException;
use App\Models\Abono;
use App\Models\AbonoDetalle;
use App\Models\Administrador;
use App\Models\Cliente;
use App\Models\CuentaCobrar;
use App\Models\Devolucion;
use App\Models\MetodoPago;
use App\Models\TurnoCaja;
use App\Models\Venta;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class CuentaCobrarService
{
    /**
     * Revisa, antes de la transacción de la venta, si el crédito pedido cabe en
     * el límite del cliente. Si no cabe, solo lo puede vender quien tiene
     * permiso de exceder el límite. Devuelve quién autorizó (o null si no hizo falta).
     *
     * Sin conexión la venta ya ocurrió: el exceso se acepta y se anota.
     */
    public static function autorizarCredito(
        Cliente $cliente,
        float $monto,
        Administrador $usuario,
        bool $offline = false,
        array &$conflictos = [],
    ): ?Administrador {
        if ((float) $cliente->limite_credito <= 0 && !$offline) {
            throw new NegocioException("{$cliente->nombre} no tiene crédito autorizado");
        }

        $disponible = $cliente->creditoDisponible();
        if ($monto <= $disponible + 0.001) {
            return null;
        }

        $mensaje = "{$cliente->nombre} solo tiene $" . number_format($disponible, 2)
            . ' de crédito disponible (límite $' . number_format((float) $cliente->limite_credito, 2) . ')';

        try {
            return AutorizacionService::autorizar($usuario, 'clientes.credito_exceder', "{$mensaje}: necesita autorización del administrador");
        } catch (NegocioException $e) {
            if (!$offline) {
                throw $e;
            }
            $conflictos[] = "Crédito de $" . number_format($monto, 2) . " sobre el límite: {$mensaje}";
            return null;
        }
    }

    /**
     * Crea la cuenta por cobrar de una venta al crédito y suma el saldo del
     * cliente. Se llama dentro de la transacción de la venta.
     */
    public static function crearDesdeVenta(Venta $venta, int $clienteId, float $monto, bool $autorizado, bool $offline): CuentaCobrar
    {
        $cliente = Cliente::whereKey($clienteId)->lockForUpdate()->first();

        // Segunda revisión con el registro bloqueado: otra caja pudo usar el crédito mientras tanto
        if (!$autorizado && !$offline && $monto > $cliente->creditoDisponible() + 0.001) {
            throw new NegocioException("{$cliente->nombre} ya no tiene crédito disponible suficiente");
        }

        $cuenta = CuentaCobrar::create([
            'cliente_id'        => $cliente->id,
            'venta_id'          => $venta->id,
            'monto'             => round($monto, 2),
            'saldo'             => round($monto, 2),
            'fecha_vencimiento' => $venta->fecha_venta->copy()->addDays($cliente->dias_credito)->toDateString(),
            'estado'            => 'pendiente',
        ]);

        $cliente->update(['saldo' => round((float) $cliente->saldo + $monto, 2)]);

        return $cuenta;
    }

    /**
     * Al anular una venta al crédito se anula su cuenta. Si ya recibió abonos,
     * primero hay que anular esos abonos (el dinero ya entró a caja).
     */
    public static function anularDeVenta(Venta $venta): void
    {
        $cuenta = CuentaCobrar::where('venta_id', $venta->id)->lockForUpdate()->first();
        if (!$cuenta || $cuenta->estado === 'anulada') {
            return;
        }

        $abonado = (float) AbonoDetalle::where('cuenta_cobrar_id', $cuenta->id)
            ->whereHas('abono', fn ($q) => $q->where('anulado', false))
            ->sum('monto');
        if ($abonado > 0) {
            throw new NegocioException('La venta tiene abonos registrados ($' . number_format($abonado, 2)
                . '): anula primero los abonos o registra una devolución');
        }

        $cliente = Cliente::whereKey($cuenta->cliente_id)->lockForUpdate()->first();
        $cliente->update(['saldo' => round(max(0, (float) $cliente->saldo - (float) $cuenta->saldo), 2)]);
        $cuenta->update(['estado' => 'anulada', 'saldo' => 0]);
    }

    /**
     * Devolución de una venta al crédito descontada de lo que debe el cliente
     * (en lugar de devolverle dinero).
     */
    public static function aplicarDevolucion(Venta $venta, float $monto): void
    {
        $cuenta = CuentaCobrar::where('venta_id', $venta->id)->lockForUpdate()->first();

        if (!$cuenta || $cuenta->estado !== 'pendiente') {
            throw new NegocioException('La venta no tiene saldo pendiente a crédito: elige otra forma de reembolso');
        }
        if ($monto > (float) $cuenta->saldo + 0.001) {
            throw new NegocioException('La devolución ($' . number_format($monto, 2) . ') supera lo que se debe de esta venta ($'
                . number_format((float) $cuenta->saldo, 2) . '): reembolsa la diferencia con otra forma de pago');
        }

        $saldo = round((float) $cuenta->saldo - $monto, 2);
        $cuenta->update(['saldo' => $saldo, 'estado' => $saldo <= 0 ? 'pagada' : 'pendiente']);

        $cliente = Cliente::whereKey($cuenta->cliente_id)->lockForUpdate()->first();
        $cliente->update(['saldo' => round(max(0, (float) $cliente->saldo - $monto), 2)]);
    }

    /**
     * Registra un abono del cliente: entra a la caja del turno abierto y se
     * aplica a sus cuentas pendientes de la más antigua a la más reciente.
     * Es idempotente por uuid.
     */
    public static function registrarAbono(Cliente $cliente, array $datos, Administrador $usuario): Abono
    {
        if ($existente = Abono::where('uuid', $datos['uuid'])->first()) {
            return $existente;
        }

        $monto = round((float) $datos['monto'], 2);

        try {
            return DB::transaction(function () use ($cliente, $datos, $usuario, $monto) {
                $cliente = Cliente::whereKey($cliente->id)->lockForUpdate()->first();

                if ($monto <= 0) {
                    throw new NegocioException('El abono debe ser mayor a 0');
                }
                if ($monto > (float) $cliente->saldo + 0.001) {
                    throw new NegocioException("{$cliente->nombre} debe $" . number_format((float) $cliente->saldo, 2)
                        . ': el abono no puede ser mayor');
                }

                $metodo = MetodoPago::find($datos['metodo_pago_id'] ?? null) ?? MetodoPago::efectivo();
                if (!$metodo || !$metodo->activo || $metodo->tipo === 'credito') {
                    throw new NegocioException('Forma de pago no válida para un abono');
                }

                $turno = TurnoCaja::where('caja_id', $datos['caja_id'])->where('estado', 'abierto')->first();
                if (!$turno) {
                    throw new NegocioException('Abre el turno de caja para recibir el abono');
                }
                CajaService::exigirTurnoPropio($turno);

                $numero = CorrelativoService::siguiente('abono', 'AB');

                $abono = Abono::create([
                    'uuid'           => $datos['uuid'],
                    'numero'         => $numero,
                    'cliente_id'     => $cliente->id,
                    'turno_id'       => $turno->id,
                    'metodo_pago_id' => $metodo->id,
                    'monto'          => $monto,
                    'referencia'     => $datos['referencia'] ?? null,
                    'usuario_id'     => $usuario->id,
                ]);

                $restante = $monto;
                $cuentas = CuentaCobrar::where('cliente_id', $cliente->id)
                    ->where('estado', 'pendiente')
                    ->orderBy('fecha_vencimiento')->orderBy('id')
                    ->lockForUpdate()->get();

                foreach ($cuentas as $cuenta) {
                    if ($restante <= 0) {
                        break;
                    }
                    $aplicado = round(min($restante, (float) $cuenta->saldo), 2);
                    $saldo = round((float) $cuenta->saldo - $aplicado, 2);

                    AbonoDetalle::create(['abono_id' => $abono->id, 'cuenta_cobrar_id' => $cuenta->id, 'monto' => $aplicado]);
                    $cuenta->update(['saldo' => $saldo, 'estado' => $saldo <= 0 ? 'pagada' : 'pendiente']);
                    $restante = round($restante - $aplicado, 2);
                }

                if ($restante > 0.001) {
                    // El saldo del cliente no coincidía con sus cuentas: no se recibe de más
                    throw new NegocioException('El abono supera las cuentas pendientes del cliente');
                }

                $cliente->update(['saldo' => round(max(0, (float) $cliente->saldo - $monto), 2)]);

                CajaService::registrarMovimiento(
                    turno:         $turno,
                    tipo:          'abono',
                    monto:         $monto,
                    concepto:      "Abono {$numero} de {$cliente->nombre}",
                    usuarioId:     $usuario->id,
                    metodoPagoId:  $metodo->id,
                    referencia:    $datos['referencia'] ?? null,
                    documentoTipo: 'abono',
                    documentoId:   $abono->id,
                );

                AuditoriaService::registrar('clientes', 'abono', $abono, null, [
                    'cliente' => $cliente->nombre,
                    'monto'   => $monto,
                    'metodo'  => $metodo->nombre,
                ], "Abono {$numero}");

                return $abono;
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($existente = Abono::where('uuid', $datos['uuid'])->first()) {
                return $existente;
            }
            throw $e;
        }
    }

    /**
     * Anula un abono del turno abierto: el dinero sale de caja y las cuentas
     * vuelven a quedar con el saldo que tenían.
     */
    public static function anularAbono(Abono $abono, string $motivo, Administrador $usuario): Abono
    {
        $autorizador = AutorizacionService::autorizar($usuario, 'pos.anular', 'Anular un abono necesita autorización del administrador');

        return DB::transaction(function () use ($abono, $motivo, $usuario, $autorizador) {
            $abono = Abono::whereKey($abono->id)->lockForUpdate()->first();

            if ($abono->anulado) {
                throw new NegocioException('El abono ya está anulado');
            }

            $turno = TurnoCaja::find($abono->turno_id);
            if (!$turno->estaAbierto()) {
                throw new NegocioException('Solo se anulan abonos del turno abierto');
            }

            CajaService::anularPorDocumento($turno, 'abono', $abono->id, "Abono {$abono->numero} anulado: {$motivo}", $usuario->id);

            foreach ($abono->detalles as $detalle) {
                $cuenta = CuentaCobrar::whereKey($detalle->cuenta_cobrar_id)->lockForUpdate()->first();
                $cuenta->update([
                    'saldo'  => round((float) $cuenta->saldo + (float) $detalle->monto, 2),
                    'estado' => $cuenta->estado === 'anulada' ? 'anulada' : 'pendiente',
                ]);
            }

            $cliente = Cliente::whereKey($abono->cliente_id)->lockForUpdate()->first();
            $cliente->update(['saldo' => round((float) $cliente->saldo + (float) $abono->monto, 2)]);

            $abono->update([
                'anulado'          => true,
                'anulado_por'      => $usuario->id,
                'anulado_at'       => now(),
                'motivo_anulacion' => $motivo,
            ]);

            AuditoriaService::registrar('clientes', 'anular_abono', $abono,
                ['monto' => (float) $abono->monto],
                ['anulado' => true, 'autorizado_por' => $autorizador->nombre],
                $motivo);

            return $abono;
        });
    }

    /**
     * Estado de cuenta: saldo anterior y cada cargo (venta al crédito) y
     * crédito (abono o devolución descontada) del periodo, con saldo corrido.
     */
    public static function estadoCuenta(Cliente $cliente, Carbon $desde, Carbon $hasta): array
    {
        $movimientos = collect();

        $cuentas = CuentaCobrar::with('venta:id,numero,fecha_venta,estado')
            ->where('cliente_id', $cliente->id)
            ->where('estado', '!=', 'anulada')
            ->get();
        foreach ($cuentas as $c) {
            $movimientos->push([
                'fecha'       => $c->venta?->fecha_venta ?? $c->created_at,
                'tipo'        => 'venta',
                'documento'   => $c->venta?->numero,
                'descripcion' => 'Venta al crédito · vence ' . $c->fecha_vencimiento->format('d-m-Y'),
                'cargo'       => (float) $c->monto,
                'abono'       => 0.0,
            ]);
        }

        $abonos = Abono::with('metodoPago:id,nombre')
            ->where('cliente_id', $cliente->id)->where('anulado', false)->get();
        foreach ($abonos as $a) {
            $movimientos->push([
                'fecha'       => $a->created_at,
                'tipo'        => 'abono',
                'documento'   => $a->numero,
                'descripcion' => 'Abono · ' . $a->metodoPago?->nombre,
                'cargo'       => 0.0,
                'abono'       => (float) $a->monto,
            ]);
        }

        // Devoluciones descontadas de la cuenta (reembolso con el método Crédito)
        $devoluciones = Devolucion::with('venta:id,numero')
            ->whereHas('venta', fn ($q) => $q->where('cliente_id', $cliente->id))
            ->whereHas('metodoPago', fn ($q) => $q->where('tipo', 'credito'))
            ->get();
        foreach ($devoluciones as $d) {
            $movimientos->push([
                'fecha'       => $d->created_at,
                'tipo'        => 'devolucion',
                'documento'   => $d->numero,
                'descripcion' => 'Devolución de venta ' . $d->venta?->numero,
                'cargo'       => 0.0,
                'abono'       => (float) $d->total,
            ]);
        }

        $movimientos = $movimientos->sortBy(fn ($m) => $m['fecha']->timestamp)->values();
        $inicio = $desde->copy()->startOfDay();
        $fin = $hasta->copy()->endOfDay();

        $anteriores = $movimientos->filter(fn ($m) => $m['fecha']->lt($inicio));
        $saldoAnterior = round($anteriores->sum('cargo') - $anteriores->sum('abono'), 2);

        $saldo = $saldoAnterior;
        $filas = $movimientos
            ->filter(fn ($m) => $m['fecha']->between($inicio, $fin))
            ->map(function ($m) use (&$saldo) {
                $saldo = round($saldo + $m['cargo'] - $m['abono'], 2);
                return [...$m, 'fecha' => $m['fecha']->format('Y-m-d H:i'), 'saldo' => $saldo];
            })->values();

        $pendientes = CuentaCobrar::with('venta:id,numero,fecha_venta')
            ->where('cliente_id', $cliente->id)->where('estado', 'pendiente')
            ->orderBy('fecha_vencimiento')->get();

        return [
            'desde'          => $desde->toDateString(),
            'hasta'          => $hasta->toDateString(),
            'saldo_anterior' => $saldoAnterior,
            'cargos'         => round($filas->sum('cargo'), 2),
            'abonos'         => round($filas->sum('abono'), 2),
            'saldo_final'    => $saldo,
            'saldo_actual'   => (float) $cliente->saldo,
            'vencido'        => round($pendientes->filter->estaVencida()->sum('saldo'), 2),
            'movimientos'    => $filas->all(),
            'pendientes'     => $pendientes->map(fn (CuentaCobrar $c) => self::formatoCuenta($c))->all(),
        ];
    }

    public static function formatoCuenta(CuentaCobrar $c): array
    {
        $dias = (int) $c->fecha_vencimiento->diffInDays(today(), false);

        return [
            'id'                => $c->id,
            'venta_id'          => $c->venta_id,
            'venta'             => $c->venta?->numero,
            'fecha'             => $c->venta?->fecha_venta?->format('Y-m-d') ?? $c->created_at->format('Y-m-d'),
            'monto'             => (float) $c->monto,
            'saldo'             => (float) $c->saldo,
            'fecha_vencimiento' => $c->fecha_vencimiento->toDateString(),
            'estado'            => $c->estado,
            'vencida'           => $c->estaVencida(),
            'dias_vencida'      => $c->estaVencida() ? $dias : 0,
        ];
    }
}
