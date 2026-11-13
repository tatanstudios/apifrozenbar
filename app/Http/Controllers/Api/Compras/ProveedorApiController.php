<?php

namespace App\Http\Controllers\Api\Compras;

use App\Http\Controllers\Controller;
use App\Models\Compra;
use App\Models\CompraDetalle;
use App\Models\CuentaPagar;
use App\Models\PagoProveedor;
use App\Models\Proveedor;
use App\Services\AuditoriaService;
use App\Services\CuentaPagarService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProveedorApiController extends Controller
{
    private function errorValidacion($validator)
    {
        return response()->json([
            'success' => 0,
            'message' => $validator->errors()->first(),
            'errors'  => $validator->errors(),
        ], 422);
    }

    public static function formato(Proveedor $p): array
    {
        return [
            'id'           => $p->id,
            'nombre'       => $p->nombre,
            'nit'          => $p->nit,
            'nrc'          => $p->nrc,
            'contacto'     => $p->contacto,
            'telefono'     => $p->telefono,
            'correo'       => $p->correo,
            'direccion'    => $p->direccion,
            'dias_credito' => $p->dias_credito,
            'saldo'        => (float) $p->saldo,
            'notas'        => $p->notas,
            'activo'       => $p->activo,
        ];
    }

    /** Días de crédito vacío = de contado (0): la columna no acepta null y la tablet lo manda vacío. */
    private static function datos(array $validados): array
    {
        if (array_key_exists('dias_credito', $validados) && $validados['dias_credito'] === null) {
            $validados['dias_credito'] = 0;
        }
        return $validados;
    }

    private function reglas(?Proveedor $proveedor = null): array
    {
        return [
            'nombre'       => 'required|string|max:200',
            'nit'          => ['nullable', 'string', 'max:30', Rule::unique('proveedores', 'nit')->ignore($proveedor?->id)],
            'nrc'          => 'nullable|string|max:30',
            'contacto'     => 'nullable|string|max:100',
            'telefono'     => 'nullable|string|max:30',
            'correo'       => 'nullable|email|max:100',
            'direccion'    => 'nullable|string|max:300',
            'dias_credito' => 'nullable|integer|min:0|max:365',
            'notas'        => 'nullable|string|max:1000',
            'activo'       => 'nullable|boolean',
        ];
    }

    private function mensajes(): array
    {
        return [
            'nombre.required' => 'El nombre del proveedor es obligatorio',
            'nit.unique'      => 'Ya hay un proveedor con ese NIT',
            'correo.email'    => 'El correo no es válido',
        ];
    }

    // GET /compras/proveedores?buscar=&estado=activos|inactivos|todos&con_saldo=1
    public function index(Request $request)
    {
        $buscar = trim((string) $request->input('buscar'));

        $proveedores = Proveedor::query()
            ->when($buscar !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('nombre', 'like', "%{$buscar}%")
                ->orWhere('nit', 'like', "%{$buscar}%")
                ->orWhere('contacto', 'like', "%{$buscar}%")))
            ->when($request->input('estado', 'activos') === 'activos', fn ($q) => $q->where('activo', true))
            ->when($request->input('estado') === 'inactivos', fn ($q) => $q->where('activo', false))
            ->when($request->boolean('con_saldo'), fn ($q) => $q->where('saldo', '>', 0))
            ->orderBy('nombre')
            ->get();

        $vencido = CuentaPagar::where('estado', 'pendiente')->whereDate('fecha_vencimiento', '<', today())
            ->groupBy('proveedor_id')->selectRaw('proveedor_id, SUM(saldo) as total')->pluck('total', 'proveedor_id');

        return response()->json([
            'success'     => 1,
            'proveedores' => $proveedores->map(fn ($p) => [...self::formato($p), 'vencido' => round((float) ($vencido[$p->id] ?? 0), 2)]),
        ]);
    }

    // POST /compras/proveedores
    public function store(Request $request)
    {
        $validator = validator($request->all(), $this->reglas(), $this->mensajes());
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $proveedor = Proveedor::create([...self::datos($validator->validated()), 'activo' => $request->boolean('activo', true)]);
        AuditoriaService::registrar('compras', 'crear_proveedor', $proveedor, null, self::formato($proveedor), "Proveedor {$proveedor->nombre}");

        return response()->json(['success' => 1, 'message' => "Proveedor {$proveedor->nombre} registrado", 'proveedor' => self::formato($proveedor)], 201);
    }

    // PUT /compras/proveedores/{proveedor}
    public function update(Request $request, Proveedor $proveedor)
    {
        $validator = validator($request->all(), $this->reglas($proveedor), $this->mensajes());
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $antes = self::formato($proveedor);
        $proveedor->update(self::datos($validator->validated()));
        AuditoriaService::registrar('compras', 'editar_proveedor', $proveedor, $antes, self::formato($proveedor), "Proveedor {$proveedor->nombre}");

        return response()->json(['success' => 1, 'message' => 'Proveedor actualizado', 'proveedor' => self::formato($proveedor)]);
    }

    // GET /compras/proveedores/{proveedor}: ficha con cuentas, pagos y productos que vende
    public function show(Proveedor $proveedor)
    {
        $pendientes = CuentaPagar::where('proveedor_id', $proveedor->id)->where('estado', 'pendiente')
            ->orderBy('fecha_vencimiento')->get();

        $pagos = PagoProveedor::with(['metodoPago:id,nombre', 'usuario:id,nombre', 'detalles.cuenta.compra:id,numero'])
            ->where('proveedor_id', $proveedor->id)->orderByDesc('id')->limit(30)->get();

        $compras = Compra::where('proveedor_id', $proveedor->id)->where('estado', 'registrada');

        // Lo que se le compra: último costo y total comprado por producto
        $productos = CompraDetalle::query()
            ->join('compras', 'compras.id', '=', 'compras_detalle.compra_id')
            ->join('productos', 'productos.id', '=', 'compras_detalle.producto_id')
            ->where('compras.proveedor_id', $proveedor->id)->where('compras.estado', 'registrada')
            ->groupBy('compras_detalle.producto_id', 'productos.nombre')
            ->selectRaw('compras_detalle.producto_id, productos.nombre, SUM(compras_detalle.subtotal) as total, MAX(compras.fecha) as ultima')
            ->orderByDesc('total')->limit(20)->get();

        return response()->json([
            'success'   => 1,
            'proveedor' => self::formato($proveedor),
            'resumen'   => [
                'compras'        => (clone $compras)->count(),
                'total_comprado' => round((float) (clone $compras)->sum('total'), 2),
                'ultima_compra'  => (clone $compras)->max('fecha'),
                'vencido'        => round($pendientes->filter->estaVencida()->sum('saldo'), 2),
            ],
            'pendientes' => $pendientes->map(fn ($c) => CuentaPagarService::formatoCuenta($c)),
            'pagos'      => $pagos->map(fn ($p) => CompraApiController::formatoPago($p)),
            'productos'  => $productos->map(fn ($p) => [
                'producto_id' => $p->producto_id,
                'nombre'      => $p->nombre,
                'total'       => round((float) $p->total, 2),
                'ultima'      => $p->ultima,
            ]),
        ]);
    }
}
