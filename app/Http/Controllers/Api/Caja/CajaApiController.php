<?php

namespace App\Http\Controllers\Api\Caja;

use App\Http\Controllers\Controller;
use App\Models\Caja;
use App\Models\MetodoPago;
use App\Services\AuditoriaService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CajaApiController extends Controller
{
    private function errorValidacion($validator)
    {
        return response()->json([
            'success' => 0,
            'message' => $validator->errors()->first(),
            'errors'  => $validator->errors(),
        ], 422);
    }

    private function formatoCaja(Caja $caja): array
    {
        $turno = $caja->turnoAbierto;

        return [
            'id'           => $caja->id,
            'nombre'       => $caja->nombre,
            'impresora_id' => $caja->impresora_id,
            'activo'       => $caja->activo,
            'turno_abierto' => $turno ? [
                'id'         => $turno->id,
                'abierto_at' => $turno->abierto_at->format('Y-m-d H:i'),
                'usuario'    => $turno->usuarioApertura?->nombre,
            ] : null,
        ];
    }

    // GET /caja/catalogos — lo que necesitan la pantalla de caja y el POS
    public function catalogos()
    {
        return response()->json([
            'success'        => 1,
            'cajas'          => Caja::with('turnoAbierto.usuarioApertura:id,nombre')
                ->where('activo', true)->orderBy('nombre')->get()
                ->map(fn ($c) => $this->formatoCaja($c)),
            'metodos_pago'   => MetodoPago::where('activo', true)->orderBy('orden')->orderBy('nombre')->get(),
            'denominaciones' => config('caja.denominaciones'),
        ]);
    }

    // ── CAJAS (configuración) ──────────────────────────────────

    // GET /caja/cajas
    public function indexCajas()
    {
        return response()->json(
            Caja::with('turnoAbierto.usuarioApertura:id,nombre')->orderBy('nombre')->get()
                ->map(fn ($c) => $this->formatoCaja($c))
        );
    }

    private function reglasCaja(?int $id = null): array
    {
        return [
            'nombre'       => ['required', 'string', 'max:100', Rule::unique('cajas', 'nombre')->ignore($id)],
            'impresora_id' => 'nullable|integer|exists:impresoras,id',
            'activo'       => 'sometimes|boolean',
        ];
    }

    // POST /caja/cajas
    public function storeCaja(Request $request)
    {
        $validator = validator($request->all(), $this->reglasCaja(), [
            'nombre.required' => 'El nombre de la caja es requerido',
            'nombre.unique'   => 'Ya existe una caja con ese nombre',
        ]);
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $caja = Caja::create($validator->validated() + ['activo' => true]);
        AuditoriaService::registrar('caja', 'crear_caja', $caja, null, $caja->toArray());

        return response()->json(['success' => 1, 'message' => 'Caja creada', 'data' => $caja], 201);
    }

    // PUT /caja/cajas/{caja}
    public function updateCaja(Request $request, Caja $caja)
    {
        $validator = validator($request->all(), $this->reglasCaja($caja->id), [
            'nombre.required' => 'El nombre de la caja es requerido',
            'nombre.unique'   => 'Ya existe una caja con ese nombre',
        ]);
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        if ($request->has('activo') && !$request->boolean('activo') && $caja->turnoAbierto()->exists()) {
            return response()->json([
                'success' => 0,
                'message' => 'No se puede desactivar una caja con turno abierto',
            ], 422);
        }

        $antes = $caja->toArray();
        $caja->update($validator->validated());
        AuditoriaService::registrar('caja', 'actualizar_caja', $caja, $antes, $caja->fresh()->toArray());

        return response()->json(['success' => 1, 'message' => 'Caja actualizada', 'data' => $caja->fresh()]);
    }

    // ── MÉTODOS DE PAGO (configuración) ────────────────────────

    // GET /caja/metodos-pago
    public function indexMetodos()
    {
        return response()->json(MetodoPago::orderBy('orden')->orderBy('nombre')->get());
    }

    private function reglasMetodo(?int $id = null): array
    {
        return [
            'nombre'              => ['required', 'string', 'max:100', Rule::unique('metodos_pago', 'nombre')->ignore($id)],
            'tipo'                => ['required', Rule::in(MetodoPago::TIPOS)],
            'afecta_efectivo'     => 'required|boolean',
            'requiere_referencia' => 'required|boolean',
            'orden'               => 'nullable|integer|min:0',
            'activo'              => 'sometimes|boolean',
        ];
    }

    private function mensajesMetodo(): array
    {
        return [
            'nombre.required' => 'El nombre es requerido',
            'nombre.unique'   => 'Ya existe un método de pago con ese nombre',
            'tipo.in'         => 'Tipo de método no válido',
        ];
    }

    // POST /caja/metodos-pago
    public function storeMetodo(Request $request)
    {
        $validator = validator($request->all(), $this->reglasMetodo(), $this->mensajesMetodo());
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $metodo = MetodoPago::create($validator->validated());
        AuditoriaService::registrar('caja', 'crear_metodo_pago', $metodo, null, $metodo->toArray());

        return response()->json(['success' => 1, 'message' => 'Método de pago creado', 'data' => $metodo], 201);
    }

    // PUT /caja/metodos-pago/{metodo}
    public function updateMetodo(Request $request, MetodoPago $metodo)
    {
        $validator = validator($request->all(), $this->reglasMetodo($metodo->id), $this->mensajesMetodo());
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $datos = $validator->validated();

        // Siempre debe quedar un efectivo activo: es el que usan ingresos, egresos y el cambio
        $dejaSinEfectivo = $metodo->tipo === 'efectivo'
            && (($datos['tipo'] ?? 'efectivo') !== 'efectivo' || (array_key_exists('activo', $datos) && !$datos['activo']))
            && !MetodoPago::where('tipo', 'efectivo')->where('activo', true)->whereKeyNot($metodo->id)->exists();

        if ($dejaSinEfectivo) {
            return response()->json([
                'success' => 0,
                'message' => 'Debe quedar al menos un método de efectivo activo',
            ], 422);
        }

        $antes = $metodo->toArray();
        $metodo->update($datos);
        AuditoriaService::registrar('caja', 'actualizar_metodo_pago', $metodo, $antes, $metodo->fresh()->toArray());

        return response()->json(['success' => 1, 'message' => 'Método de pago actualizado', 'data' => $metodo->fresh()]);
    }
}
