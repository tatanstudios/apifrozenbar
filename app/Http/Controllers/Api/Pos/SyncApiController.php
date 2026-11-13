<?php

namespace App\Http\Controllers\Api\Pos;

use App\Exceptions\NegocioException;
use App\Http\Controllers\Controller;
use App\Models\Venta;
use App\Services\VentaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SyncApiController extends Controller
{
    // GET /pos/ping — el POS lo usa para saber si hay conexión con el servidor
    public function ping()
    {
        return response()->json(['success' => 1, 'hora' => now()->toIso8601String()]);
    }

    private function reglasVenta(): array
    {
        return [
            'uuid'                    => 'required|uuid',
            'caja_id'                 => 'required|integer|exists:cajas,id',
            'turno_id'                => 'nullable|integer',
            'numero_provisional'      => 'nullable|string|max:30',
            // Hora del dispositivo: no puede ser futura (5 min de tolerancia) ni de hace más de 30 días
            'fecha_venta'             => 'required|date|before_or_equal:' . now()->addMinutes(5)->toIso8601String()
                . '|after_or_equal:' . now()->subDays(30)->toIso8601String(),
            'items'                   => 'required|array|min:1|max:200',
            'items.*.producto_id'     => 'required|integer',
            'items.*.cantidad'        => 'required|numeric|gt:0|max:99999',
            'items.*.precio_unitario' => 'required|numeric|min:0',
            'items.*.descuento_tipo'  => 'nullable|in:porcentaje,monto',
            'items.*.descuento_valor' => 'nullable|numeric|min:0',
            'items.*.modificadores'   => 'nullable|array|max:60',
            'items.*.modificadores.*' => 'integer',
            'items.*.ajustes'         => 'nullable|array|max:50',
            'items.*.ajustes.*.producto_id' => 'required|integer',
            'items.*.ajustes.*.cantidad'    => 'required|numeric|min:0',
            'items.*.notas'           => 'nullable|string|max:200',
            'descuento_global'        => 'nullable|array',
            'descuento_global.tipo'   => 'required_with:descuento_global|in:porcentaje,monto',
            'descuento_global.valor'  => 'required_with:descuento_global|numeric|min:0',
            'pagos'                   => 'present|array|max:10',
            'pagos.*.metodo_pago_id'  => 'required|integer',
            'pagos.*.monto'           => 'required|numeric|min:0|max:999999',
            'pagos.*.referencia'      => 'nullable|string|max:100',
            'observaciones'           => 'nullable|string|max:300',
            'cliente_id'              => 'nullable|integer',
        ];
    }

    /**
     * POST /pos/sync  {ventas: [...]}
     * Recibe las ventas hechas sin conexión. Cada una se procesa en su propia
     * transacción: si una falla, las demás se guardan igual. Es idempotente por
     * uuid, así que el dispositivo puede reenviar el lote completo sin duplicar.
     */
    public function ventas(Request $request)
    {
        $request->validate([
            'ventas'   => 'required|array|min:1|max:50',
            'ventas.*' => 'array',
        ], [
            'ventas.max' => 'Envía como máximo 50 ventas por lote',
        ]);

        $resultados = [];

        foreach ($request->ventas as $i => $datos) {
            $uuid = $datos['uuid'] ?? null;
            $validator = validator($datos, $this->reglasVenta());

            if ($validator->fails()) {
                $resultados[] = ['uuid' => $uuid, 'estado' => 'error', 'message' => $validator->errors()->first()];
                continue;
            }

            try {
                $yaExistia = Venta::where('uuid', $uuid)->exists();
                $venta = VentaService::registrar($validator->validated(), $request->user(), offline: true);

                $resultados[] = [
                    'uuid'       => $uuid,
                    'estado'     => $yaExistia ? 'ya_existia' : 'creada',
                    'numero'     => $venta->numero,
                    'venta_id'   => $venta->id,
                    'conflictos' => $venta->conflictos ?? [],
                ];
            } catch (NegocioException $e) {
                $resultados[] = ['uuid' => $uuid, 'estado' => 'error', 'message' => $e->getMessage()];
            } catch (\Throwable $e) {
                Log::error("Error al sincronizar la venta {$uuid}: " . $e->getMessage());
                $resultados[] = ['uuid' => $uuid, 'estado' => 'error', 'message' => 'Error interno al registrar la venta'];
            }
        }

        $conError = collect($resultados)->where('estado', 'error')->count();

        return response()->json([
            'success'    => 1,
            'message'    => $conError ? "{$conError} venta(s) no se pudieron registrar" : 'Ventas sincronizadas',
            'resultados' => $resultados,
        ]);
    }
}
