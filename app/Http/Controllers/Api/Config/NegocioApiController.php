<?php

namespace App\Http\Controllers\Api\Config;

use App\Http\Controllers\Controller;
use App\Models\ConfiguracionNegocio;
use App\Services\AuditoriaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class NegocioApiController extends Controller
{
    // GET /configuracion/negocio
    public function mostrar()
    {
        return response()->json([
            'success' => 1,
            'data'    => ConfiguracionNegocio::actual(),
        ]);
    }

    // POST /configuracion/negocio (multipart, por el logo)
    public function actualizar(Request $request)
    {
        // El logo solo se valida si viene un archivo: la tablet manda los datos que leyó (con el
        // nombre del logo actual como texto) y eso no es cambiar el logo
        $entrada = $request->hasFile('logo') ? $request->all() : $request->except('logo');
        $validator = validator($entrada, [
            'nombre'                  => 'required|string|max:200',
            'razon_social'            => 'nullable|string|max:200',
            'nit'                     => 'nullable|string|max:30',
            'nrc'                     => 'nullable|string|max:30',
            'actividad_economica'     => 'nullable|string|max:200',
            'tipo_establecimiento'    => 'nullable|string|max:100',
            'direccion'               => 'nullable|string|max:300',
            'telefono'                => 'nullable|string|max:30',
            'correo'                  => 'nullable|email|max:100',
            'logo'                    => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            'moneda'                  => 'required|string|size:3',
            'simbolo_moneda'          => 'required|string|max:5',
            'permitir_stock_negativo' => 'required|boolean',
            'descuento_max_sin_autorizacion' => 'nullable|numeric|min:0|max:100',
            'cierre_ciego'            => 'nullable|boolean',
            'aviso_cambio'            => 'nullable|boolean',
            'ticket_por_comprar'      => 'nullable|boolean',
            'mensaje_ticket'          => 'nullable|string|max:500',
        ], [
            'nombre.required'         => 'El nombre del negocio es requerido',
            'correo.email'            => 'El correo no es válido',
            'logo.image'              => 'El logo debe ser una imagen válida',
            'logo.mimes'              => 'El logo debe ser JPG o PNG',
            'logo.max'                => 'El logo debe pesar menos de 2MB',
            'moneda.required'         => 'La moneda es requerida',
            'moneda.size'             => 'La moneda debe tener 3 caracteres (ej. USD)',
            'simbolo_moneda.required' => 'El símbolo de moneda es requerido',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => 0,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        $config    = ConfiguracionNegocio::actual();
        $antes     = $config->toArray();
        $logoNuevo = null;

        try {
            $datos = $validator->safe()->except('logo');
            if (array_key_exists('descuento_max_sin_autorizacion', $datos)) {
                $datos['descuento_max_sin_autorizacion'] = (float) ($datos['descuento_max_sin_autorizacion'] ?? 0);
            }

            // Normalizar booleanos que llegan como string desde multipart
            foreach (['permitir_stock_negativo', 'cierre_ciego', 'aviso_cambio', 'ticket_por_comprar'] as $campo) {
                if (array_key_exists($campo, $datos)) {
                    $datos[$campo] = filter_var($datos[$campo], FILTER_VALIDATE_BOOLEAN);
                }
            }

            if ($request->hasFile('logo')) {
                $foto      = $request->file('logo');
                $logoNuevo = 'logo_' . time() . '_' . Str::random(10) . '.' . $foto->getClientOriginalExtension();
                $foto->move(Storage::disk('archivos')->path(''), $logoNuevo);
                $datos['logo'] = $logoNuevo;
            }

            $logoAnterior = $config->logo;
            $config->update($datos);

            // Borrar el logo anterior solo si se reemplazó correctamente
            if ($logoNuevo && $logoAnterior && Storage::disk('archivos')->exists($logoAnterior)) {
                Storage::disk('archivos')->delete($logoAnterior);
            }

            AuditoriaService::registrar('configuracion', 'actualizar', $config, $antes, $config->fresh()->toArray());

            return response()->json([
                'success' => 1,
                'message' => 'Configuración guardada correctamente',
                'data'    => $config->fresh(),
            ]);

        } catch (\Throwable $e) {
            if ($logoNuevo && Storage::disk('archivos')->exists($logoNuevo)) {
                Storage::disk('archivos')->delete($logoNuevo);
            }

            Log::error('Error al guardar configuración del negocio: ' . $e->getMessage());

            return response()->json([
                'success' => 0,
                'message' => 'Error al guardar la configuración',
            ], 500);
        }
    }
}
