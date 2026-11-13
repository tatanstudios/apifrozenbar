<?php

namespace App\Http\Controllers\Api\Manuales;

use App\Http\Controllers\Controller;
use App\Services\AuditoriaService;
use App\Services\ManualService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Manuales de uso: un solo contenido (resources/manuales/manuales.json) para la web y la tablet.
 * Cada usuario recibe solo los manuales que su rol tiene permitidos (permisos «manual.{id}»).
 */
class ManualApiController extends Controller
{
    // GET /manuales
    public function index(Request $request): JsonResponse
    {
        $ruta = resource_path('manuales/manuales.json');

        return response()->json([
            'actualizado' => is_file($ruta) ? date('Y-m-d', filemtime($ruta)) : null,
            'modulos' => ManualService::visiblesPara($request->user()),
        ]);
    }

    // GET /manuales/permisos: qué manuales tiene cada rol (el admin los ve todos y no se edita)
    public function permisos(): JsonResponse
    {
        ManualService::sincronizarPermisos();
        $roles = Role::where('guard_name', 'api')->where('name', '!=', 'admin')->orderBy('name')->with('permissions')->get();
        $esManual = fn ($p) => str_starts_with($p, ManualService::PREFIJO);

        return response()->json([
            'roles' => $roles->map(function (Role $rol) use ($esManual) {
                $suyos = $rol->permissions->pluck('name');
                return [
                    'id' => $rol->id,
                    'nombre' => $rol->name,
                    'usuarios' => $rol->users()->count(),
                    'ver_seccion' => $suyos->contains('manuales.ver'),
                    // Permisos del rol, para avisar cuando un manual es de un módulo que no puede usar
                    'permisos' => $suyos->reject($esManual)->values(),
                    'manuales' => $suyos->filter($esManual)->map(fn ($p) => substr($p, strlen(ManualService::PREFIJO)))->values(),
                ];
            }),
            'modulos' => collect(ManualService::modulos())->map(fn ($mo) => [
                'id' => $mo['id'],
                'titulo' => $mo['titulo'],
                'manuales' => collect($mo['manuales'])->map(fn ($m) => [
                    'id' => $m['id'], 'titulo' => $m['titulo'], 'resumen' => $m['resumen'], 'permisos' => $m['permisos'],
                ])->values(),
            ]),
        ]);
    }

    // PUT /manuales/permisos/{rol} {manuales: [ids]}: reemplaza solo los manuales del rol
    public function guardarPermisos(Request $request, int $rol): JsonResponse
    {
        $rol = Role::where('guard_name', 'api')->findOrFail($rol);
        if ($rol->name === 'admin') {
            return response()->json(['message' => 'El administrador ve todos los manuales'], 422);
        }

        ManualService::sincronizarPermisos();
        $validos = ManualService::manuales()->pluck('id');
        $datos = $request->validate([
            'manuales' => 'present|array',
            'manuales.*' => ['string', function ($atributo, $valor, $fallo) use ($validos) {
                if (! $validos->contains($valor)) {
                    $fallo('Uno de los manuales no existe');
                }
            }],
        ]);

        $antes = $rol->permissions->pluck('name')->filter(fn ($p) => str_starts_with($p, ManualService::PREFIJO))->values();
        $despues = collect($datos['manuales'])->unique()->map(fn ($id) => ManualService::PREFIJO . $id)->values();
        $quitados = $antes->diff($despues)->values();
        $agregados = $despues->diff($antes)->values();

        DB::transaction(function () use ($rol, $quitados, $agregados) {
            foreach ($quitados as $p) {
                $rol->revokePermissionTo($p);
            }
            if ($agregados->isNotEmpty()) {
                $rol->givePermissionTo($agregados->all());
            }
        });
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        AuditoriaService::registrar('roles', 'manuales', $rol,
            ['quitados' => $quitados->all()],
            ['agregados' => $agregados->all()],
            "Manuales del rol {$rol->name}");

        return response()->json(['message' => "Manuales del rol {$rol->name} guardados ({$despues->count()} de {$validos->count()})"]);
    }
}
