<?php

namespace App\Http\Controllers\Api\Pos;

use App\Exceptions\NegocioException;
use App\Http\Controllers\Api\Mesas\MesaApiController;
use App\Http\Controllers\Controller;
use App\Models\Orden;
use App\Models\OrdenItem;
use App\Models\OrdenTicket;
use App\Models\Terminal;
use App\Services\ImpresionService;
use App\Services\OrdenService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Pedidos de mostrador "por cobrar": el cliente pide en la caja, la comanda sale a
 * cocina o barra en ese momento y se cobra cuando recoge o termina.
 *
 * Cada pedido es una cuenta con el nombre del cliente dentro de la orden abierta de la
 * terminal de mostrador de la caja (tipo "caja"). Así usa todo lo de las mesas:
 * comandas por área, cancelación con aviso CANCELADO, extras, ingredientes, existencia
 * apartada y cobro con varias formas de pago (POST /mesas/ordenes/{orden}/cobrar).
 */
class MostradorApiController extends Controller
{
    private const REGLAS_ITEMS = [
        'items'                         => 'required|array|min:1|max:50',
        'items.*.producto_id'           => 'required|integer',
        'items.*.cantidad'              => 'required|numeric|gt:0|max:9999',
        'items.*.modificadores'         => 'nullable|array|max:60',
        'items.*.modificadores.*'       => 'integer',
        'items.*.notas'                 => 'nullable|string|max:200',
        'items.*.ajustes'               => 'nullable|array|max:30',
        'items.*.ajustes.*.producto_id' => 'required|integer',
        'items.*.ajustes.*.cantidad'    => 'required|numeric|min:0',
    ];

    private function validar(Request $request, array $reglas, array $mensajes = []): array
    {
        $validator = validator($request->all(), $reglas, $mensajes);
        if ($validator->fails()) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                'success' => 0,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422));
        }
        return $validator->validated();
    }

    private static function terminalDe(int $cajaId): Terminal
    {
        return Terminal::where('tipo', 'caja')->where('caja_id', $cajaId)->where('activo', true)->first()
            ?? throw new NegocioException('Esta caja no tiene terminal de mostrador activa: créala en Mesas → Terminales (tipo caja)');
    }

    /** Pedidos con algo por cobrar de la orden abierta de la terminal (más antiguos primero) */
    private static function pendientes(?Orden $orden): Collection
    {
        if (!$orden) {
            return collect();
        }
        $formato = MesaApiController::formatoOrden($orden);

        return collect($formato['tickets'])
            ->where('estado', 'abierto')
            ->filter(fn ($t) => $t['por_cobrar'] > 0)
            ->map(function ($t) use ($orden) {
                $vigentes = collect($t['items'])->where('estado', '!=', 'cancelado');
                $ticket = OrdenTicket::find($t['id']);
                return [
                    'orden_id'   => $orden->id,
                    'ticket_id'  => $t['id'],
                    'nombre'     => $t['nombre'],
                    'total'      => $t['total'],
                    'por_cobrar' => $t['por_cobrar'],
                    'piezas'     => round($vigentes->sum('cantidad'), 4),
                    'creado_at'  => $ticket?->created_at?->format('Y-m-d H:i:s'),
                    'usuario'    => $vigentes->first()['usuario'] ?? null,
                    'items'      => $t['items'],
                ];
            })
            ->sortBy('creado_at')
            ->values();
    }

    private static function respuestaImpresion(Collection $comandas, int $usuarioId): array
    {
        // Imprimir nunca frena el pedido: si falla, se puede reimprimir la comanda
        $trabajos = $comandas->flatMap(fn ($c) => ImpresionService::imprimirComanda($c, $usuarioId));

        return [
            'impresas'      => $trabajos->where('estado', 'impreso')->count(),
            'errores'       => $trabajos->where('estado', 'error')->pluck('error')->values(),
            'sin_impresora' => $comandas->isNotEmpty() && $trabajos->isEmpty(),
            // Para imprimir desde el navegador (un ticket por área) si la térmica no está o falla
            'comandas'      => $comandas->map(fn ($c) => MesaApiController::formatoComanda($c->fresh()))->values(),
        ];
    }

    // GET /mostrador/pedidos?caja_id=
    public function index(Request $request)
    {
        $datos = $this->validar($request, ['caja_id' => 'required|integer|exists:cajas,id']);
        $terminal = self::terminalDe((int) $datos['caja_id']);
        $orden = Orden::where('terminal_id', $terminal->id)->where('estado', 'abierta')->first();

        // Para avisar al cerrar el turno: mesas que todavía tienen algo por cobrar
        $mesas = Orden::where('estado', 'abierta')->where('terminal_id', '!=', $terminal->id)
            ->whereHas('items', fn ($q) => $q->whereNull('venta_id')->where('estado', '!=', 'cancelado'))
            ->with('terminal:id,nombre')->get();

        return response()->json([
            'success'          => 1,
            'pedidos'          => self::pendientes($orden),
            'mesas_por_cobrar' => $mesas->map(fn ($o) => $o->terminal->nombre)->values(),
        ]);
    }

    // POST /mostrador/pedidos {uuid, caja_id, nombre, items}: crea el pedido y envía la comanda
    public function store(Request $request)
    {
        $datos = $this->validar($request, [
            'uuid'    => 'required|uuid',
            'caja_id' => 'required|integer|exists:cajas,id',
            'nombre'  => 'required|string|max:60',
            ...self::REGLAS_ITEMS,
        ], [
            'nombre.required' => 'Escribe el nombre del cliente para llamarlo',
            'items.required'  => 'Agrega al menos un producto',
        ]);
        $usuario = $request->user();
        $terminal = self::terminalDe((int) $datos['caja_id']);
        $clave = "mostrador:pedido:{$datos['uuid']}";

        // Si la tablet reintenta el mismo envío (se cortó la conexión), no se duplica la comanda
        return Cache::lock("{$clave}:lock", 30)->block(10, function () use ($datos, $usuario, $terminal, $clave) {
            if ($ticketId = Cache::get($clave)) {
                $ticket = OrdenTicket::findOrFail($ticketId);
                return response()->json([
                    'success' => 1,
                    'message' => "Pedido de {$ticket->nombre} ya estaba enviado",
                    'pedido'  => self::pendientes($ticket->orden)->firstWhere('ticket_id', $ticket->id),
                    'impresion' => ['impresas' => 0, 'errores' => [], 'sin_impresora' => false, 'comandas' => []],
                ]);
            }

            [$ticket, $comandas] = DB::transaction(function () use ($datos, $usuario, $terminal) {
                $orden = OrdenService::abrir($terminal, $usuario, [], true);
                // La primera cuenta de una orden nueva sale vacía: se usa para este pedido
                $ticket = OrdenTicket::where('orden_id', $orden->id)->where('estado', 'abierto')
                    ->whereDoesntHave('items')->orderBy('id')->first()
                    ?? OrdenService::nuevoTicket($orden, null);
                $ticket->update(['nombre' => trim($datos['nombre'])]);

                OrdenService::agregarItems($orden, $ticket->id, $datos['items'], $usuario);
                $comandas = OrdenService::enviar($orden, $usuario);

                return [$ticket, $comandas];
            });
            Cache::put($clave, $ticket->id, now()->addDay());

            $impresion = self::respuestaImpresion($comandas, $usuario->id);

            return response()->json([
                'success'   => 1,
                'message'   => "Pedido de {$ticket->nombre} enviado" . ($comandas->isNotEmpty()
                    ? ' a ' . $comandas->map(fn ($c) => $c->area?->nombre)->filter()->join(' y ') : ''),
                'pedido'    => self::pendientes($ticket->orden->fresh())->firstWhere('ticket_id', $ticket->id),
                'impresion' => $impresion,
                'avisos'    => OrdenService::$avisos,
            ], 201);
        });
    }

    // POST /mostrador/pedidos/{ticket}/items {items}: agrega algo más y lo envía
    public function agregar(Request $request, OrdenTicket $ticket)
    {
        $datos = $this->validar($request, self::REGLAS_ITEMS, ['items.required' => 'Agrega al menos un producto']);
        $usuario = $request->user();
        $orden = $ticket->orden;

        if ($orden->estado !== 'abierta' || $orden->terminal->tipo !== 'caja' || $ticket->estado !== 'abierto') {
            throw new NegocioException('Ese pedido ya se cobró o se cerró');
        }

        $comandas = DB::transaction(function () use ($orden, $ticket, $datos, $usuario) {
            OrdenService::agregarItems($orden, $ticket->id, $datos['items'], $usuario);
            return OrdenService::enviar($orden, $usuario);
        });

        return response()->json([
            'success'   => 1,
            'message'   => "Se agregó al pedido de {$ticket->nombre}",
            'pedido'    => self::pendientes($orden->fresh())->firstWhere('ticket_id', $ticket->id),
            'impresion' => self::respuestaImpresion($comandas, $usuario->id),
            'avisos'    => OrdenService::$avisos,
        ]);
    }
}
