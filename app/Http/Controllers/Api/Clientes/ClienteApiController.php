<?php

namespace App\Http\Controllers\Api\Clientes;

use App\Http\Controllers\Controller;
use App\Models\Abono;
use App\Models\Cliente;
use App\Models\ConfiguracionNegocio;
use App\Models\CuentaCobrar;
use App\Models\Venta;
use App\Services\AuditoriaService;
use App\Services\CuentaCobrarService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClienteApiController extends Controller
{
    private function errorValidacion($validator)
    {
        return response()->json([
            'success' => 0,
            'message' => $validator->errors()->first(),
            'errors'  => $validator->errors(),
        ], 422);
    }

    public static function formato(Cliente $c): array
    {
        return [
            'id'                 => $c->id,
            'nombre'             => $c->nombre,
            'documento'          => $c->documento,
            'telefono'           => $c->telefono,
            'correo'             => $c->correo,
            'direccion'          => $c->direccion,
            'limite_credito'     => (float) $c->limite_credito,
            'dias_credito'       => $c->dias_credito,
            'saldo'              => (float) $c->saldo,
            'credito_disponible' => $c->creditoDisponible(),
            'notas'              => $c->notas,
            'activo'             => $c->activo,
        ];
    }

    private function reglas(?Cliente $cliente = null): array
    {
        return [
            'nombre'         => 'required|string|max:200',
            'documento'      => ['nullable', 'string', 'max:30', Rule::unique('clientes', 'documento')->ignore($cliente?->id)],
            'telefono'       => 'nullable|string|max:30',
            'correo'         => 'nullable|email|max:100',
            'direccion'      => 'nullable|string|max:300',
            'limite_credito' => 'nullable|numeric|min:0|max:999999',
            'dias_credito'   => 'nullable|integer|min:0|max:365',
            'notas'          => 'nullable|string|max:500',
            'activo'         => 'nullable|boolean',
        ];
    }

    /**
     * Límite de crédito vacío = sin crédito (0). Días de crédito y estado vacíos no se tocan (al crear
     * quedan los de la base: 30 días, activo). Esas columnas no aceptan null y la tablet manda vacíos.
     */
    private static function sinNulos(array $datos): array
    {
        if (array_key_exists('limite_credito', $datos) && $datos['limite_credito'] === null) {
            $datos['limite_credito'] = 0;
        }
        foreach (['dias_credito', 'activo'] as $campo) {
            if (array_key_exists($campo, $datos) && $datos[$campo] === null) {
                unset($datos[$campo]);
            }
        }
        return $datos;
    }

    private function mensajes(): array
    {
        return [
            'nombre.required'  => 'El nombre del cliente es obligatorio',
            'documento.unique' => 'Ya hay un cliente con ese DUI o NIT',
            'correo.email'     => 'El correo no es válido',
        ];
    }

    // GET /clientes?buscar=&estado=activos|inactivos|todos&con_saldo=1&page=
    public function index(Request $request)
    {
        $buscar = trim((string) $request->input('buscar'));

        $clientes = Cliente::query()
            ->when($buscar !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('nombre', 'like', "%{$buscar}%")
                ->orWhere('documento', 'like', "%{$buscar}%")
                ->orWhere('telefono', 'like', "%{$buscar}%")))
            ->when($request->input('estado', 'activos') === 'activos', fn ($q) => $q->where('activo', true))
            ->when($request->input('estado') === 'inactivos', fn ($q) => $q->where('activo', false))
            ->when($request->boolean('con_saldo'), fn ($q) => $q->where('saldo', '>', 0))
            ->orderBy('nombre')
            ->paginate(min($request->integer('per_page', 25), 100));

        $vencido = CuentaCobrar::whereIn('cliente_id', collect($clientes->items())->pluck('id'))
            ->where('estado', 'pendiente')->whereDate('fecha_vencimiento', '<', today())
            ->groupBy('cliente_id')->selectRaw('cliente_id, SUM(saldo) as total')
            ->pluck('total', 'cliente_id');

        return response()->json([
            'success'       => 1,
            'clientes'      => collect($clientes->items())->map(fn (Cliente $c) => [
                ...self::formato($c),
                'vencido' => round((float) ($vencido[$c->id] ?? 0), 2),
            ]),
            'pagina'        => $clientes->currentPage(),
            'total_paginas' => $clientes->lastPage(),
            'total'         => $clientes->total(),
        ]);
    }

    // POST /clientes
    public function store(Request $request)
    {
        $validator = validator($request->all(), $this->reglas(), $this->mensajes());
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $datos = $validator->validated();
        // Solo quien gestiona cuentas por cobrar asigna crédito
        if (!$request->user()->can('cuentas_cobrar.gestionar')) {
            unset($datos['limite_credito'], $datos['dias_credito']);
        }

        // refresh: trae los valores por defecto de la base (días de crédito)
        $cliente = Cliente::create([...self::sinNulos($datos), 'activo' => $datos['activo'] ?? true])->refresh();
        AuditoriaService::registrar('clientes', 'crear', $cliente, null, self::formato($cliente), "Cliente {$cliente->nombre}");

        return response()->json([
            'success' => 1,
            'message' => "Cliente {$cliente->nombre} registrado",
            'cliente' => self::formato($cliente),
        ], 201);
    }

    // PUT /clientes/{cliente}
    public function update(Request $request, Cliente $cliente)
    {
        $validator = validator($request->all(), $this->reglas($cliente), $this->mensajes());
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $datos = $validator->validated();
        if (!$request->user()->can('cuentas_cobrar.gestionar')) {
            unset($datos['limite_credito'], $datos['dias_credito']);
        }

        $antes = self::formato($cliente);
        $cliente->update(self::sinNulos($datos));
        AuditoriaService::registrar('clientes', 'editar', $cliente, $antes, self::formato($cliente), "Cliente {$cliente->nombre}");

        return response()->json([
            'success' => 1,
            'message' => 'Cliente actualizado',
            'cliente' => self::formato($cliente),
        ]);
    }

    // GET /clientes/{cliente}: ficha con resumen de compras y crédito
    public function show(Cliente $cliente)
    {
        $ventas = Venta::where('cliente_id', $cliente->id)->where('estado', '!=', 'anulada');
        $ultima = (clone $ventas)->max('fecha_venta');

        $pendientes = CuentaCobrar::with('venta:id,numero,fecha_venta')
            ->where('cliente_id', $cliente->id)->where('estado', 'pendiente')
            ->orderBy('fecha_vencimiento')->get();

        $abonos = Abono::with(['metodoPago:id,nombre', 'usuario:id,nombre'])
            ->where('cliente_id', $cliente->id)
            ->orderByDesc('id')->limit(20)->get();

        return response()->json([
            'success'  => 1,
            'cliente'  => self::formato($cliente),
            'resumen'  => [
                'compras'       => (clone $ventas)->count(),
                'total_comprado' => round((float) (clone $ventas)->sum('total'), 2),
                'ultima_compra' => $ultima ? Carbon::parse($ultima)->format('Y-m-d H:i') : null,
                'vencido'       => round($pendientes->filter->estaVencida()->sum('saldo'), 2),
            ],
            'pendientes' => $pendientes->map(fn ($c) => CuentaCobrarService::formatoCuenta($c)),
            'abonos'     => $abonos->map(fn (Abono $a) => self::formatoAbono($a)),
        ]);
    }

    public static function formatoAbono(Abono $a): array
    {
        return [
            'id'               => $a->id,
            'numero'           => $a->numero,
            'fecha'            => $a->created_at->format('Y-m-d H:i'),
            'monto'            => (float) $a->monto,
            'metodo'           => $a->metodoPago?->nombre,
            'referencia'       => $a->referencia,
            'usuario'          => $a->usuario?->nombre,
            'turno_id'         => $a->turno_id,
            'anulado'          => $a->anulado,
            'motivo_anulacion' => $a->motivo_anulacion,
        ];
    }

    private function periodo(Request $request): array
    {
        $hasta = $request->filled('hasta') ? Carbon::parse($request->hasta) : today();
        $desde = $request->filled('desde') ? Carbon::parse($request->desde) : $hasta->copy()->subMonths(3)->startOfMonth();

        return [$desde, $hasta];
    }

    // GET /clientes/{cliente}/estado-cuenta?desde=&hasta=
    public function estadoCuenta(Request $request, Cliente $cliente)
    {
        $validator = validator($request->all(), [
            'desde' => 'nullable|date',
            'hasta' => 'nullable|date|after_or_equal:desde',
        ]);
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        [$desde, $hasta] = $this->periodo($request);

        return response()->json([
            'success' => 1,
            'cliente' => self::formato($cliente),
            'estado'  => CuentaCobrarService::estadoCuenta($cliente, $desde, $hasta),
        ]);
    }

    // GET /clientes/{cliente}/estado-cuenta/pdf?desde=&hasta=
    public function estadoCuentaPdf(Request $request, Cliente $cliente)
    {
        $validator = validator($request->all(), [
            'desde' => 'nullable|date',
            'hasta' => 'nullable|date|after_or_equal:desde',
        ]);
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        [$desde, $hasta] = $this->periodo($request);

        $pdf = Pdf::loadView('pdf.estado-cuenta', [
            'negocio' => ConfiguracionNegocio::actual(),
            'cliente' => $cliente,
            'estado'  => CuentaCobrarService::estadoCuenta($cliente, $desde, $hasta),
            'emitido' => now(),
        ])->setPaper('letter')->setOption('isFontSubsettingEnabled', true);

        $nombre = 'estado-cuenta-' . str($cliente->nombre)->slug() . '-' . $hasta->format('Y-m-d') . '.pdf';

        return $pdf->download($nombre);
    }

    // POST /clientes/{cliente}/abonos
    public function registrarAbono(Request $request, Cliente $cliente)
    {
        $validator = validator($request->all(), [
            'uuid'           => 'required|uuid',
            'caja_id'        => 'required|integer|exists:cajas,id',
            'monto'          => 'required|numeric|gt:0|max:999999',
            'metodo_pago_id' => 'nullable|integer|exists:metodos_pago,id',
            'referencia'     => 'nullable|string|max:100',
        ], [
            'monto.required' => 'Indica el monto del abono',
            'monto.gt'       => 'El abono debe ser mayor a 0',
        ]);
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $abono = CuentaCobrarService::registrarAbono($cliente, $validator->validated(), $request->user());
        $abono->load(['metodoPago:id,nombre', 'usuario:id,nombre']);

        return response()->json([
            'success' => 1,
            'message' => "Abono {$abono->numero} de $" . number_format((float) $abono->monto, 2) . ' registrado',
            'abono'   => self::formatoAbono($abono),
            'cliente' => self::formato($cliente->fresh()),
        ], $abono->wasRecentlyCreated ? 201 : 200);
    }

    // POST /clientes/abonos/{abono}/anular
    public function anularAbono(Request $request, Abono $abono)
    {
        $validator = validator($request->all(), [
            'motivo'           => 'required|string|max:300',
        ], [
            'motivo.required' => 'Indica el motivo de la anulación',
        ]);
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $abono = CuentaCobrarService::anularAbono($abono, $request->motivo, $request->user());

        return response()->json([
            'success' => 1,
            'message' => "Abono {$abono->numero} anulado",
            'cliente' => self::formato($abono->cliente->fresh()),
        ]);
    }

    // GET /cuentas-cobrar?estado=pendiente|vencidas|pagada&buscar=: cartera de todos los clientes
    public function cuentas(Request $request)
    {
        $estado = $request->input('estado', 'pendiente');
        $buscar = trim((string) $request->input('buscar'));

        $consulta = CuentaCobrar::query()
            ->when($estado === 'vencidas', fn ($q) => $q->where('estado', 'pendiente')->whereDate('fecha_vencimiento', '<', today()))
            ->when(in_array($estado, ['pendiente', 'pagada', 'anulada'], true), fn ($q) => $q->where('estado', $estado))
            ->when($buscar !== '', fn ($q) => $q->whereHas('cliente', fn ($c) => $c->where('nombre', 'like', "%{$buscar}%")));

        // Antigüedad de la cartera pendiente (siempre sobre todo lo pendiente)
        $pendiente = CuentaCobrar::where('estado', 'pendiente')->get(['saldo', 'fecha_vencimiento']);
        $antiguedad = ['por_vencer' => 0.0, 'd1_30' => 0.0, 'd31_60' => 0.0, 'd61_90' => 0.0, 'mas_90' => 0.0];
        foreach ($pendiente as $c) {
            $dias = (int) $c->fecha_vencimiento->diffInDays(today(), false);
            $clave = match (true) {
                $dias <= 0  => 'por_vencer',
                $dias <= 30 => 'd1_30',
                $dias <= 60 => 'd31_60',
                $dias <= 90 => 'd61_90',
                default     => 'mas_90',
            };
            $antiguedad[$clave] += (float) $c->saldo;
        }

        $cuentas = $consulta->with(['cliente:id,nombre,telefono', 'venta:id,numero,fecha_venta'])
            ->orderBy('fecha_vencimiento')->orderBy('id')
            ->paginate(min($request->integer('per_page', 25), 100));

        return response()->json([
            'success'       => 1,
            'cuentas'       => collect($cuentas->items())->map(fn (CuentaCobrar $c) => [
                ...CuentaCobrarService::formatoCuenta($c),
                'cliente_id' => $c->cliente_id,
                'cliente'    => $c->cliente?->nombre,
                'telefono'   => $c->cliente?->telefono,
            ]),
            'resumen'       => [
                'total'      => round($pendiente->sum('saldo'), 2),
                'clientes'   => Cliente::where('saldo', '>', 0)->count(),
                'antiguedad' => array_map(fn ($v) => round($v, 2), $antiguedad),
            ],
            'pagina'        => $cuentas->currentPage(),
            'total_paginas' => $cuentas->lastPage(),
            'total'         => $cuentas->total(),
        ]);
    }
}
