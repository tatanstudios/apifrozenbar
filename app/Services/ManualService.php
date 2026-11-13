<?php

namespace App\Services;

use App\Models\Administrador;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Manuales de uso (resources/manuales/manuales.json) y qué manuales ve cada rol.
 *
 * Cada manual tiene su permiso «manual.{id}». No aparecen en la matriz de Roles y permisos:
 * se manejan en su propia pantalla (Manuales → Permisos por rol). «manuales.ver» sigue
 * siendo el interruptor general de la sección.
 */
class ManualService
{
    public const PREFIJO = 'manual.';

    /** @return array<int, array> módulos con sus manuales */
    public static function modulos(): array
    {
        $datos = json_decode((string) @file_get_contents(resource_path('manuales/manuales.json')), true);
        return $datos['modulos'] ?? [];
    }

    public static function manuales(): Collection
    {
        return collect(self::modulos())->flatMap(fn ($m) => $m['manuales']);
    }

    /**
     * Crea el permiso de cada manual nuevo y borra los de manuales que ya no existen.
     * Un manual nuevo se da de entrada a los roles que pueden usar su módulo (o a todos si no
     * pide ningún permiso); después el administrador lo quita o lo da desde su pantalla.
     */
    public static function sincronizarPermisos(): void
    {
        $manuales = self::manuales();
        $existentes = Permission::where('guard_name', 'api')->where('name', 'like', self::PREFIJO . '%')->pluck('name');
        $esperados = $manuales->map(fn ($m) => self::PREFIJO . $m['id']);

        $faltan = $manuales->reject(fn ($m) => $existentes->contains(self::PREFIJO . $m['id']));
        $sobran = $existentes->diff($esperados);
        if ($faltan->isEmpty() && $sobran->isEmpty()) {
            return;
        }

        foreach ($faltan as $m) {
            Permission::create(['name' => self::PREFIJO . $m['id'], 'guard_name' => 'api', 'description' => "Manual: {$m['titulo']}"]);
        }
        Permission::where('guard_name', 'api')->whereIn('name', $sobran)->delete();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $roles = Role::where('guard_name', 'api')->where('name', '!=', 'admin')->with('permissions')->get();
        foreach ($roles as $rol) {
            self::darSegunModulos($rol, $faltan->pluck('id')->all());
        }
    }

    /**
     * Da a un rol los manuales de los módulos que puede usar (y los que no piden permiso).
     * Con $soloEstos se limita a esos ids (los manuales recién creados).
     */
    public static function darSegunModulos(Role $rol, ?array $soloEstos = null): void
    {
        $delRol = $rol->permissions->pluck('name');
        $dar = self::manuales()
            ->filter(fn ($m) => $soloEstos === null || in_array($m['id'], $soloEstos, true))
            ->filter(fn ($m) => empty($m['permisos']) || $delRol->intersect($m['permisos'])->isNotEmpty())
            ->map(fn ($m) => self::PREFIJO . $m['id'])
            ->values()->all();
        if ($dar) {
            $rol->givePermissionTo($dar);
        }
    }

    /** Módulos con solo los manuales que ese usuario puede ver (el admin ve todos) */
    public static function visiblesPara(Administrador $usuario): array
    {
        self::sincronizarPermisos();
        if ($usuario->hasRole('admin')) {
            return self::modulos();
        }

        $suyos = $usuario->getAllPermissions()->pluck('name')->flip();
        return collect(self::modulos())
            ->map(fn ($mo) => [...$mo, 'manuales' => array_values(array_filter($mo['manuales'], fn ($m) => $suyos->has(self::PREFIJO . $m['id'])))])
            ->filter(fn ($mo) => count($mo['manuales']) > 0)
            ->values()->all();
    }
}
