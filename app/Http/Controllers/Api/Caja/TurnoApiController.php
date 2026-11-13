<?php

namespace App\Http\Controllers\Api\Caja;

use App\Http\Controllers\Controller;
use App\Models\Caja;
use App\Models\Compra;
use App\Models\ConfiguracionNegocio;
use App\Models\EntradaInventario;
use App\Models\Gasto;
use App\Models\Merma;
use App\Models\MovimientoCaja;
use App\Models\OrdenCompra;
use App\Models\Produccion;
use App\Models\TurnoCaja;
use App\Services\AuditoriaService;
use App\Services\CajaService;
use App\Services\InventarioService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TurnoApiController extends Controller
{
    private function errorValidacion($validator)
    {
        return response()->json([
            'success' => 0,
            'message' => $validator->errors()->first(),
            'errors'  => $validator->errors(),
        ], 422);
    }

    // Cierre ciego: quien no supervisa cajas no ve el efectivo esperado del turno abierto
    private function ocultarEsperado(Request $request): bool
    {
        return CajaService::ocultarEsperado();
    }

    private function formatoMovimiento(MovimientoCaja $m): array
    {
        return [
            'id'               => $m->id,
            'uuid'             => $m->uuid,
            'fecha'            => $m->created_at->format('Y-m-d H:i'),
            'tipo'             => $m->tipo,
            'tipo_texto'       => MovimientoCaja::TIPOS[$m->tipo] ?? $m->tipo,
            'entrada'          => $m->esEntrada(),
            'metodo_pago'      => $m->metodoPago?->nombre,
            'monto'            => (float) $m->monto,
            'concepto'         => $m->concepto,
            'referencia'       => $m->referencia,
            'documento_tipo'   => $m->documento_tipo,
            'documento_id'     => $m->documento_id,
            'usuario'          => $m->usuario?->nombre,
            'anulado'          => $m->anulado,
            'anulado_por'      => $m->anuladoPor?->nombre,
            'motivo_anulacion' => $m->motivo_anulacion,
            'manual'           => in_array($m->tipo, MovimientoCaja::TIPOS_MANUALES, true),
        ];
    }

    private function formatoTurno(TurnoCaja $t): array
    {
        return [
            'id'                     => $t->id,
            'caja_id'                => $t->caja_id,
            'caja'                   => $t->caja?->nombre,
            'estado'                 => $t->estado,
            'abierto_at'             => $t->abierto_at->format('Y-m-d H:i'),
            'cerrado_at'             => $t->cerrado_at?->format('Y-m-d H:i'),
            'usuario_apertura_id'    => $t->usuario_apertura_id,
            'usuario_apertura'       => $t->usuarioApertura?->nombre,
            'usuario_cierre'         => $t->usuarioCierre?->nombre,
            'fondo_inicial'          => (float) $t->fondo_inicial,
            'total_ventas'           => (float) $t->total_ventas,
            'total_ingresos'         => (float) $t->total_ingresos,
            'total_egresos'          => (float) $t->total_egresos,
            'efectivo_esperado'      => $t->efectivo_esperado !== null ? (float) $t->efectivo_esperado : null,
            'efectivo_contado'       => $t->efectivo_contado !== null ? (float) $t->efectivo_contado : null,
            'diferencia'             => $t->diferencia !== null ? (float) $t->diferencia : null,
            'observaciones_apertura' => $t->observaciones_apertura,
            'observaciones_cierre'   => $t->observaciones_cierre,
        ];
    }

    // Lo que se registró durante el turno fuera de la caja: compras, gastos, inventario, producción, mermas
    private function registros(TurnoCaja $turno): array
    {
        $fila = fn (string $tipo, $r, ?string $numero, string $descripcion, ?float $monto, bool $anulado = false) => [
            'tipo'        => $tipo,
            'id'          => $r->id,
            'numero'      => $numero,
            'descripcion' => $descripcion,
            'monto'       => $monto,
            'usuario'     => $r->usuario?->nombre,
            'fecha'       => $r->created_at->format('Y-m-d H:i'),
            'anulado'     => $anulado,
        ];
        $de = fn (string $modelo, array $con = []) => $modelo::where('turno_id', $turno->id)->with(['usuario:id,nombre', ...$con])->get();

        return collect()
            ->concat($de(OrdenCompra::class, ['proveedor:id,nombre'])->map(fn ($o) => $fila('Orden de compra', $o, $o->numero, $o->proveedor?->nombre ?? '', (float) $o->total, $o->estado === 'cancelada')))
            ->concat($de(Compra::class, ['proveedor:id,nombre'])->map(fn ($c) => $fila('Compra', $c, $c->numero, $c->proveedor?->nombre ?? '', (float) $c->total, $c->estado === 'anulada')))
            ->concat($de(Gasto::class)->map(fn ($g) => $fila('Gasto', $g, $g->numero, $g->descripcion ?? '', (float) $g->monto, (bool) $g->anulado)))
            ->concat($de(EntradaInventario::class)->map(fn ($e) => $fila('Entrada de inventario', $e, null, $e->descripcion ?? '', null)))
            ->concat($de(Produccion::class, ['producto:id,nombre'])->map(fn ($p) => $fila('Producción', $p, $p->numero, $p->producto?->nombre . ' × ' . (float) $p->cantidad_producida, (float) $p->costo_total, $p->estado === 'anulada')))
            ->concat($de(Merma::class, ['producto:id,nombre'])->map(fn ($m) => $fila('Merma', $m, null, $m->producto?->nombre . ' × ' . (float) $m->cantidad, (float) $m->costo_total, (bool) $m->anulada)))
            ->sortBy('fecha')->values()->all();
    }

    // Detalle completo: datos, resumen, movimientos y arqueos
    private function detalle(TurnoCaja $turno, bool $ocultarEsperado = false): array
    {
        $turno->load(['caja', 'usuarioApertura:id,nombre', 'usuarioCierre:id,nombre']);

        // Un turno cerrado usa su foto; uno abierto se calcula en vivo
        $resumen = CajaService::resumen($turno);

        $arqueos = $turno->arqueos()->with('usuario:id,nombre')->orderBy('id')->get()
            ->map(fn ($a) => [
                'id'                => $a->id,
                'tipo'              => $a->tipo,
                'fecha'             => $a->created_at->format('Y-m-d H:i'),
                'denominaciones'    => $a->denominaciones,
                'total_contado'     => (float) $a->total_contado,
                'efectivo_esperado' => $ocultarEsperado ? null : (float) $a->efectivo_esperado,
                'diferencia'        => $ocultarEsperado ? null : (float) $a->diferencia,
                'observaciones'     => $a->observaciones,
                'usuario'           => $a->usuario?->nombre,
            ]);

        $movimientos = $turno->movimientos()
            ->with(['metodoPago:id,nombre', 'usuario:id,nombre', 'anuladoPor:id,nombre'])
            ->orderByDesc('id')
            ->get()
            ->map(fn ($m) => $this->formatoMovimiento($m));

        if ($ocultarEsperado) {
            $resumen['efectivo_esperado'] = null;
        }

        return [
            'turno'       => $this->formatoTurno($turno),
            'resumen'     => $resumen,
            'movimientos' => $movimientos,
            'arqueos'     => $arqueos,
            'registros'   => $this->registros($turno),
            'cierre_ciego' => $ocultarEsperado,
        ];
    }

    private function turnoAbiertoOFalla(TurnoCaja $turno)
    {
        if (!$turno->estaAbierto()) {
            return response()->json(['success' => 0, 'status' => 'BUSINESS_RULE', 'message' => 'El turno de caja está cerrado'], 422);
        }
        return null;
    }

    // GET /caja/turno-actual?caja_id=
    public function actual(Request $request)
    {
        $validator = validator($request->all(), ['caja_id' => 'required|integer|exists:cajas,id'], [
            'caja_id.required' => 'Selecciona la caja de este dispositivo',
            'caja_id.exists'   => 'La caja seleccionada no existe',
        ]);
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $caja  = Caja::find($request->caja_id);
        $turno = TurnoCaja::where('caja_id', $caja->id)->where('estado', 'abierto')->first();

        // Turno de otra persona que se fue sin cerrarlo: solo se puede cerrar (ver CajaService::exigirTurnoPropio)
        $usuario = $request->user();
        $ajeno = $turno && !CajaService::puedeOperarEn($turno, $usuario);

        return response()->json([
            'success'     => 1,
            'caja'        => ['id' => $caja->id, 'nombre' => $caja->nombre, 'activo' => $caja->activo],
            'turno_ajeno' => $ajeno,
            // Datos del negocio → «Avisar si no alcanza el cambio» (lo usa la ventana de cobro)
            'aviso_cambio' => (bool) ConfiguracionNegocio::actual()->aviso_cambio,
        ] + ($turno ? $this->detalle($turno, $this->ocultarEsperado($request)) : ['turno' => null] + $this->alAbrir($caja)));
    }

    /**
     * Lo que ve quien recibe la caja antes de abrir: quién la cerró y qué quedó por comprar
     * (en vivo, por si alguien ya compró algo después del cierre).
     */
    private function alAbrir(Caja $caja): array
    {
        $anterior = TurnoCaja::with('usuarioCierre:id,nombre')->where('caja_id', $caja->id)
            ->where('estado', 'cerrado')->latest('cerrado_at')->first();

        return [
            'turno_anterior' => $anterior ? [
                'id'               => $anterior->id,
                'cerrado_at'       => $anterior->cerrado_at?->format('Y-m-d H:i'),
                'cerro'            => $anterior->usuarioCierre?->nombre,
                'efectivo_contado' => $anterior->efectivo_contado !== null ? (float) $anterior->efectivo_contado : null,
            ] : null,
            // El efectivo no se retira al cerrar: lo contado queda como fondo del siguiente turno
            'fondo_sugerido' => CajaService::efectivoQueQuedo($caja->id),
            'por_comprar' => InventarioService::productosEnAlerta(),
        ];
    }

    // POST /caja/turnos/abrir
    public function abrir(Request $request)
    {
        $validator = validator($request->all(), [
            'caja_id'        => 'required|integer|exists:cajas,id',
            'fondo_inicial'  => 'nullable|numeric|min:0|max:99999',
            'denominaciones' => 'nullable|array',
            'observaciones'  => 'nullable|string|max:500',
        ], [
            'caja_id.required'       => 'Selecciona la caja',
            'fondo_inicial.min'      => 'El fondo inicial no puede ser negativo',
        ]);
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        // Lo que quedó en la gaveta al cerrar el turno anterior pasa al nuevo turno
        $queQuedo = CajaService::efectivoQueQuedo((int) $request->caja_id);

        // Si se contó por denominaciones, manda el conteo del servidor; sin monto, lo que quedó
        $fondo = $request->filled('denominaciones')
            ? CajaService::totalDenominaciones(array_filter($request->denominaciones, fn ($c) => (int) $c > 0))
            : ($request->filled('fondo_inicial') ? (float) $request->fondo_inicial : ($queQuedo ?? 0.0));

        // Abrir con otro monto (ej. el dueño se llevó dinero sin registrarlo) pide explicar por qué
        if ($queQuedo !== null && abs($fondo - $queQuedo) >= 0.005 && !trim((string) $request->observaciones)) {
            return response()->json([
                'success' => 0,
                'status'  => 'BUSINESS_RULE',
                'message' => 'En la caja quedaron $' . number_format($queQuedo, 2) . ' del turno anterior. Si abres con otro monto, escribe en observaciones por qué.',
            ], 422);
        }

        // El turno es de quien inició sesión: cada empleado entra con su usuario y abre su turno
        $responsable = $request->user();
        $turno = CajaService::abrir($request->caja_id, $fondo, $responsable->id, $request->observaciones);

        AuditoriaService::registrar('caja', 'abrir_turno', $turno, null, [
            'responsable'    => $responsable->nombre,
            'caja_id'        => $turno->caja_id,
            'fondo_inicial'  => $fondo,
            'fondo_anterior' => $queQuedo,
            'denominaciones' => $request->denominaciones,
        ]);

        return response()->json([
            'success' => 1,
            'message' => 'Turno abierto',
        ] + $this->detalle($turno, $this->ocultarEsperado($request)), 201);
    }

    // POST /caja/turnos/{turno}/movimientos — ingresos y egresos manuales
    public function movimiento(Request $request, TurnoCaja $turno)
    {
        $validator = validator($request->all(), [
            'uuid'           => 'nullable|uuid',
            'tipo'           => ['required', Rule::in(MovimientoCaja::TIPOS_MANUALES)],
            'monto'          => 'required|numeric|gt:0|max:99999',
            'concepto'       => 'required|string|max:300',
            'metodo_pago_id' => 'nullable|integer|exists:metodos_pago,id',
            'referencia'     => 'nullable|string|max:100',
        ], [
            'tipo.in'           => 'Solo se pueden registrar ingresos o egresos',
            'monto.required'    => 'Indica el monto',
            'monto.gt'          => 'El monto debe ser mayor a 0',
            'concepto.required' => 'Indica el concepto',
        ]);
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        if ($error = $this->turnoAbiertoOFalla($turno)) {
            return $error;
        }
        CajaService::exigirTurnoPropio($turno);

        $mov = CajaService::registrarMovimiento(
            turno:        $turno,
            tipo:         $request->tipo,
            monto:        (float) $request->monto,
            concepto:     $request->concepto,
            usuarioId:    $request->user()->id,
            metodoPagoId: $request->metodo_pago_id,
            referencia:   $request->referencia,
            uuid:         $request->uuid,
        );

        if ($mov->wasRecentlyCreated) {
            AuditoriaService::registrar('caja', $mov->tipo, $mov, null, $mov->toArray(), $mov->concepto);
        }

        return response()->json([
            'success'    => 1,
            'message'    => $mov->tipo === 'ingreso' ? 'Ingreso registrado' : 'Egreso registrado',
            'movimiento' => $this->formatoMovimiento($mov->load(['metodoPago', 'usuario'])),
        ], $mov->wasRecentlyCreated ? 201 : 200);
    }

    // POST /caja/movimientos/{movimiento}/anular
    public function anularMovimiento(Request $request, MovimientoCaja $movimiento)
    {
        $validator = validator($request->all(), ['motivo' => 'required|string|max:300'], [
            'motivo.required' => 'Indica el motivo de la anulación',
        ]);
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $mov = CajaService::anularMovimiento($movimiento, $request->motivo, $request->user()->id);

        AuditoriaService::registrar('caja', 'anular_movimiento', $mov,
            ['anulado' => false], ['anulado' => true, 'monto' => (float) $mov->monto, 'tipo' => $mov->tipo], $request->motivo);

        return response()->json(['success' => 1, 'message' => 'Movimiento anulado']);
    }

    private function reglasConteo(): array
    {
        return [
            'denominaciones'   => 'nullable|array',
            'denominaciones.*' => 'integer|min:0',
            'efectivo_contado' => 'nullable|required_without:denominaciones|numeric|min:0|max:999999',
            'observaciones'    => 'nullable|string|max:500',
        ];
    }

    private function mensajesConteo(): array
    {
        return [
            'denominaciones.*.integer'          => 'Las cantidades de billetes y monedas deben ser números enteros',
            'efectivo_contado.required_without' => 'Indica el efectivo contado',
        ];
    }

    // POST /caja/turnos/{turno}/arqueos
    public function arqueo(Request $request, TurnoCaja $turno)
    {
        $validator = validator($request->all(), $this->reglasConteo(), $this->mensajesConteo());
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $arqueo = CajaService::arqueo(
            $turno,
            $request->denominaciones,
            $request->filled('efectivo_contado') ? (float) $request->efectivo_contado : null,
            $request->user()->id,
            $request->observaciones,
        );

        AuditoriaService::registrar('caja', 'arqueo', $turno, null, $arqueo->toArray());

        $ocultar = $this->ocultarEsperado($request);

        return response()->json([
            'success'       => 1,
            'message'       => 'Arqueo registrado',
            'total_contado' => (float) $arqueo->total_contado,
            'diferencia'    => $ocultar ? null : (float) $arqueo->diferencia,
        ], 201);
    }

    // POST /caja/turnos/{turno}/cerrar
    public function cerrar(Request $request, TurnoCaja $turno)
    {
        $validator = validator($request->all(), $this->reglasConteo(), $this->mensajesConteo());
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $turno = CajaService::cerrar(
            $turno,
            $request->denominaciones,
            $request->filled('efectivo_contado') ? (float) $request->efectivo_contado : null,
            $request->user()->id, // lo cierra quien tiene la sesión
            $request->observaciones,
        );

        AuditoriaService::registrar('caja', 'cerrar_turno', $turno, null, $this->formatoTurno($turno));

        // Ya cerrado se muestra todo, también en cierre ciego
        return response()->json([
            'success' => 1,
            'message' => 'Turno cerrado',
        ] + $this->detalle($turno));
    }

    // POST /caja/turnos/{turno}/reabrir
    public function reabrir(Request $request, TurnoCaja $turno)
    {
        $validator = validator($request->all(), ['motivo' => 'required|string|max:300'], [
            'motivo.required' => 'Indica el motivo para reabrir el turno',
        ]);
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $antes = $this->formatoTurno($turno);
        $turno = CajaService::reabrir($turno);

        AuditoriaService::registrar('caja', 'reabrir_turno', $turno, $antes, $this->formatoTurno($turno), $request->motivo);

        return response()->json(['success' => 1, 'message' => 'Turno reabierto'] + $this->detalle($turno));
    }

    // GET /caja/turnos/{turno} — reporte del turno
    public function mostrar(Request $request, TurnoCaja $turno)
    {
        $usuario = $request->user();

        // Sin permiso de historial, solo los turnos que el usuario abrió o cerró, o el abierto que comparte
        if (!$usuario->can('caja.historial')
            && $turno->usuario_apertura_id !== $usuario->id
            && $turno->usuario_cierre_id !== $usuario->id
            && !($turno->estaAbierto() && CajaService::puedeOperarEn($turno, $usuario))) {
            return response()->json(['success' => false, 'status' => 'FORBIDDEN', 'message' => 'No tienes permisos para ver este turno.'], 403);
        }

        $ocultar = $turno->estaAbierto() && $this->ocultarEsperado($request);

        return response()->json(['success' => 1] + $this->detalle($turno, $ocultar));
    }

    // GET /caja/turnos?desde=&hasta=&caja_id=&usuario_id=&estado=&page=
    public function historial(Request $request)
    {
        $validator = validator($request->all(), [
            'desde'      => 'nullable|date',
            'hasta'      => 'nullable|date|after_or_equal:desde',
            'caja_id'    => 'nullable|integer',
            'usuario_id' => 'nullable|integer',
            'estado'     => 'nullable|in:abierto,cerrado',
        ]);
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $hasta = $request->filled('hasta') ? Carbon::parse($request->hasta)->endOfDay() : now()->endOfDay();
        $desde = $request->filled('desde') ? Carbon::parse($request->desde)->startOfDay() : $hasta->copy()->subDays(29)->startOfDay();

        $consulta = TurnoCaja::with(['caja:id,nombre', 'usuarioApertura:id,nombre', 'usuarioCierre:id,nombre'])
            ->whereBetween('abierto_at', [$desde, $hasta])
            ->when($request->filled('caja_id'), fn ($q) => $q->where('caja_id', $request->caja_id))
            ->when($request->filled('usuario_id'), fn ($q) => $q->where(fn ($w) => $w
                ->where('usuario_apertura_id', $request->usuario_id)
                ->orWhere('usuario_cierre_id', $request->usuario_id)))
            ->when($request->filled('estado'), fn ($q) => $q->where('estado', $request->estado));

        $totales = (clone $consulta)->where('estado', 'cerrado')->selectRaw('
                COUNT(*) as turnos,
                COALESCE(SUM(total_ventas), 0) as ventas,
                COALESCE(SUM(CASE WHEN diferencia < 0 THEN diferencia ELSE 0 END), 0) as faltantes,
                COALESCE(SUM(CASE WHEN diferencia > 0 THEN diferencia ELSE 0 END), 0) as sobrantes
            ')->first();

        $turnos = $consulta->orderByDesc('abierto_at')->paginate($request->integer('per_page', 25));

        return response()->json([
            'success'       => 1,
            'desde'         => $desde->toDateString(),
            'hasta'         => $hasta->toDateString(),
            'turnos'        => collect($turnos->items())->map(fn ($t) => $this->formatoTurno($t)),
            'totales'       => [
                'turnos'    => (int) $totales->turnos,
                'ventas'    => round((float) $totales->ventas, 2),
                'faltantes' => round((float) $totales->faltantes, 2),
                'sobrantes' => round((float) $totales->sobrantes, 2),
            ],
            'usuarios'      => \App\Models\Administrador::whereIn('id', TurnoCaja::select('usuario_apertura_id')
                ->union(TurnoCaja::whereNotNull('usuario_cierre_id')->select('usuario_cierre_id')))
                ->orderBy('nombre')->get(['id', 'nombre']),
            'pagina'        => $turnos->currentPage(),
            'total_paginas' => $turnos->lastPage(),
            'total'         => $turnos->total(),
        ]);
    }
}
