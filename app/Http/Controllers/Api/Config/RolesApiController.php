<?php

namespace App\Http\Controllers\Api\Config;

use App\Services\ManualService;
use App\Http\Controllers\Controller;
use App\Models\Administrador;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Validator;
use App\Services\AuditoriaService;

class RolesApiController extends Controller
{
    public function listadoRoles()
    {
        $roles = Role::all()->pluck('name', 'id');
        return ['success' => true, 'roles' => $roles];
    }


    public function borrarRolGlobal(Request $request)
    {
        $request->validate([
            'idrol' => 'required|integer'
        ]);

        DB::beginTransaction();

        try {
            $idRol = (int) $request->input('idrol');

            $role = Role::where('id', $idRol)
                ->where('guard_name', 'api')
                ->firstOrFail();

            // Quitar permisos asociados
            $role->syncPermissions([]);

            // Eliminar rol
            $antes = ['nombre' => $role->name, 'permisos' => $role->permissions()->pluck('name')->toArray()];
            $role->delete();

            DB::commit();

            AuditoriaService::registrar('roles', 'eliminar', null, $antes, null, "Rol eliminado: {$antes['nombre']}");

            return [
                'success' => true,
                'message' => 'Rol eliminado correctamente'
            ];

        } catch (ModelNotFoundException $e) {
            DB::rollBack();
            Log::warning('Rol no encontrado', ['idrol' => $request->idrol]);

            return [
                'success' => false,
                'message' => 'Rol no encontrado'
            ];

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Error al borrar rol', [
                'idrol' => $request->idrol,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Error interno al eliminar rol'
            ];
        }
    }





    public function tablaRolesPermisos($id){

        $role = Role::findById($id, 'api');

        $permisos = $role->permissions()
            ->select('id', 'name')
            ->get();

        return response()->json([
            'success' => true,
            'permisos' => $permisos
        ]);
    }


    public function borrarPermiso(Request $request)
    {
        $request->validate([
            'idrol' => 'required|integer',
            'idpermiso' => 'required|integer',
        ]);

        DB::beginTransaction();

        try {
            // Rol (MISMO guard)
            $role = Role::findById($request->idrol, 'api');

            // Permiso (MISMO guard)
            $permission = Permission::findById($request->idpermiso, 'api');

            // Revocar permiso
            $role->revokePermissionTo($permission);

            DB::commit();

            AuditoriaService::registrar('roles', 'quitar_permiso', $role, ['permiso' => $permission->name], null);

            return ['success' => true];
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Error al borrar permiso del rol', [
                'idrol' => $request->idrol,
                'idpermiso' => $request->idpermiso,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => 0,
                'message' => 'No se pudo eliminar el permiso',
            ], 500);
        }
    }



    public function nuevoRol(Request $request)
    {
        $validar = Validator::make($request->all(), [
            'nombre' => 'required|string|max:255',
        ]);

        if ($validar->fails()) {
            return response()->json(['success' => 0, 'errors' => $validar->errors()], 422);
        }

        // Verificar si existe el rol
        if (Role::where('name', $request->nombre)->exists()) {
            return response()->json(['success' => 1, 'message' => 'El rol ya existe'], 409);
        }

        Role::create([
            'name' => $request->nombre,
            'guard_name' => 'api'
        ]);

        return response()->json(['success' => 2, 'message' => 'Rol creado exitosamente'], 201);
    }


    public function agregarPermiso(Request $request)
    {
        Log::info($request->all());

        $validar = Validator::make($request->all(), [
            'idrol' => 'required|integer|exists:roles,id',
            'idpermiso' => 'required|integer|exists:permissions,id',
        ]);

        if ($validar->fails()) {
            return response()->json([
                'success' => 0,
                'errors' => $validar->errors()
            ], 422);
        }

        try {
            // 🔒 Rol solo guard api
            $role = Role::where('id', $request->idrol)
                ->where('guard_name', 'api')
                ->firstOrFail();

            // 🔒 Permiso existente (NO se crea)
            $permission = Permission::where('id', $request->idpermiso)
                ->where('guard_name', 'api')
                ->firstOrFail();

            // ⚠️ Ya asignado
            if ($role->hasPermissionTo($permission)) {
                return response()->json([
                    'success' => 2,
                    'message' => 'El rol ya tiene este permiso'
                ], 409);
            }

            // ✅ Asignar permiso
            $role->givePermissionTo($permission);

            AuditoriaService::registrar('roles', 'asignar_permiso', $role, null, ['permiso' => $permission->name]);

            return response()->json([
                'success' => 1,
                'message' => 'Permiso asignado correctamente',
                'permiso' => [
                    'id' => $permission->id,
                    'name' => $permission->name
                ]
            ]);

        } catch (\Throwable $e) {
            Log::error('Error al asignar permiso', [
                'idrol' => $request->idrol,
                'idpermiso' => $request->idpermiso,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => 0,
                'message' => 'Error al asignar el permiso'
            ], 500);
        }
    }



    public function tablaTodosPermisos(){

        $permisos = Permission::orderBy('name')->get();

        return [
            'success' => 1,
            'permisos' => $permisos
        ];
    }


    public function borrarPermisoGlobal(Request $request)
    {
        $request->validate([
            'idpermiso' => 'required|integer|exists:permissions,id',
        ]);

        try {
            $permiso = Permission::find($request->idpermiso);

            if (!$permiso) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Permiso no encontrado',
                ], 404);
            }

            $permiso->delete();

            return response()->json([
                'success' => 1,
                'message' => 'Permiso eliminado correctamente',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Error al eliminar permiso',
            ], 500);
        }
    }


    public function nuevoPermisoExtra(Request $request)
    {
        $request->validate([
            'nombre' => 'required|string|max:150',
            'descripcion' => 'nullable|string|max:200',
        ]);

        try {
            $permisoExistente = Permission::where('name', $request->nombre)
                ->where('guard_name', 'api')
                ->first();

            if ($permisoExistente) {
                return response()->json([
                    'success' => 2,
                    'message' => 'El permiso ya existe',
                    'permiso' => $permisoExistente->name, // 👈 CLAVE
                ], 409);
            }

            Permission::create([
                'name' => $request->nombre,
                'guard_name' => 'api',
                'description' => $request->descripcion ?? null,
            ]);

            return response()->json([
                'success' => 1,
                'message' => 'Permiso creado correctamente',
            ]);

        } catch (\Throwable $e) {
            Log::error('Error al crear permiso: ' . $e->getMessage());

            return response()->json([
                'success' => 0,
                'message' => 'Error al crear el permiso',
            ], 500);
        }
    }



    /**
     * Matriz de permisos del rol, agrupada por módulo. Incluye los permisos
     * extra creados a mano (fuera del catálogo) en «Otros».
     */
    public function matrizPermisos($id)
    {
        $rol = Role::findOrFail($id);
        $catalogo = config('permisos.permisos');
        $ayuda = config('permisos.ayuda', []);
        $modulos = config('permisos.modulos');
        $asignados = $rol->permissions->pluck('name')->flip();

        $grupos = [];
        // Los permisos de cada manual («manual.{id}») tienen su propia pantalla: aquí no salen
        $sinManuales = Permission::where('guard_name', 'api')->where('name', 'not like', ManualService::PREFIJO . '%');
        foreach ($sinManuales->orderBy('name')->get() as $permiso) {
            $prefijo = explode('.', $permiso->name)[0];
            $modulo = isset($catalogo[$permiso->name]) ? ($modulos[$prefijo] ?? ucfirst($prefijo)) : 'Otros';
            $grupos[$modulo][] = [
                'nombre'      => $permiso->name,
                'descripcion' => $catalogo[$permiso->name] ?? $permiso->description ?? $permiso->name,
                // Explicación larga (qué deja hacer, dónde y qué pasa sin él) para el botón «?»
                'ayuda'       => $ayuda[$permiso->name] ?? null,
                'asignado'    => $rol->name === 'admin' || $asignados->has($permiso->name),
            ];
        }

        // Mismo orden que el menú (el del catálogo de módulos); «Otros» al final
        $orden = array_flip(array_values($modulos));
        uksort($grupos, fn ($a, $b) => ($orden[$a] ?? 999) <=> ($orden[$b] ?? 999));

        return response()->json([
            'success'  => 1,
            'rol'      => ['id' => $rol->id, 'nombre' => $rol->name, 'es_admin' => $rol->name === 'admin', 'usuarios' => $rol->users()->count()],
            'modulos'  => collect($grupos)->map(fn ($permisos, $modulo) => ['modulo' => $modulo, 'permisos' => $permisos])->values(),
        ]);
    }

    // PUT /admin/roles/{id}/permisos {permisos: [...]}: reemplaza los permisos del rol
    public function guardarMatriz(Request $request, $id)
    {
        $rol = Role::findOrFail($id);

        if ($rol->name === 'admin') {
            return response()->json(['success' => 0, 'message' => 'El rol admin tiene acceso total: sus permisos no se editan'], 422);
        }

        $validator = Validator::make($request->all(), [
            'permisos'   => 'present|array',
            'permisos.*' => 'string|exists:permissions,name',
        ], [
            'permisos.*.exists' => 'Uno de los permisos no existe',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => 0, 'message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $antes = $rol->permissions->pluck('name')->sort()->values()->all();
        // La matriz no muestra los manuales: se conservan los que el rol ya tenía
        $esManual = fn ($p) => str_starts_with($p, ManualService::PREFIJO);
        $rol->syncPermissions(collect($request->permisos)->reject($esManual)
            ->merge(collect($antes)->filter($esManual))->unique()->values()->all());
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        $despues = $rol->fresh()->permissions->pluck('name')->sort()->values()->all();

        AuditoriaService::registrar('roles', 'permisos', $rol,
            ['quitados' => array_values(array_diff($antes, $despues))],
            ['agregados' => array_values(array_diff($despues, $antes))],
            "Permisos del rol {$rol->name}");

        return response()->json([
            'success' => 1,
            'message' => 'Permisos del rol actualizados (' . count($despues) . ' en total)',
        ]);
    }

    public function tablaUsuarios(){
        $usuarios = Administrador::orderBy('id', 'ASC')
            ->get()
            ->map(function ($u) {
                return [
                    'id' => $u->id,
                    'nombre' => $u->nombre,
                    'correo' => $u->email,
                    'estado' => $u->estado,
                    'rol' => $u->getRoleNames()->first() ?? 'Sin rol',
                    'ultimo_acceso' => $u->ultimo_acceso?->format('Y-m-d H:i'),
                    'pin_actualizado_at' => $u->pin_actualizado_at?->format('Y-m-d H:i'),
                ];
            });

        return response()->json($usuarios);
    }

    public function nuevoUsuario(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nombre' => 'required|string|max:255',
            'email' => 'required|email|unique:administradores,email',
            'password' => 'required|string|min:8',
            'rol' => 'required|string|exists:roles,name',
        ], [
            // Mensajes personalizados
            'nombre.required' => 'El nombre es obligatorio',
            'email.required' => 'El correo es obligatorio',
            'email.email' => 'El correo no es válido',
            'email.unique' => 'Este correo ya está registrado',
            'password.required' => 'La contraseña es obligatoria',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres',
            'rol.required' => 'El rol es obligatorio',
            'rol.exists' => 'El rol seleccionado no existe',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();
        try {
            $usuario = Administrador::create([
                'nombre' => $request->nombre,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'estado' => true,
            ]);

            // Spatie
            $usuario->assignRole($request->rol);

            DB::commit();

            AuditoriaService::registrar('usuarios', 'crear', $usuario, null, [
                'nombre' => $usuario->nombre,
                'email'  => $usuario->email,
                'rol'    => $request->rol,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Usuario creado correctamente',
                'data' => $usuario,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error al crear usuario: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al crear el usuario',
            ], 500);
        }
    }


    public function informacionAdministrador(Request $request){
        $usuario = Administrador::find($request->id);

        if (!$usuario) {
            return response()->json([
                'success' => 2,
                'message' => 'Usuario no encontrado'
            ]);
        }

        // Todos los roles disponibles
        $roles = Role::orderBy('name')->pluck('name', 'id');

        // Rol actual del usuario (Spatie)
        $rolActual = $usuario->getRoleNames()->first(); // string | null

        return response()->json([
            'success' => 1,
            'info' => [
                'id' => $usuario->id,
                'nombre' => $usuario->nombre,
                'email' => $usuario->email,
                'estado' => $usuario->estado,
            ],
            'roles' => $roles,
            'rol_actual' => $rolActual,
        ]);
    }



    public function actualizarAdministrador(Request $request, $id)
    {
        $usuario = Administrador::find($id);

        if (!$usuario) {
            return response()->json([
                'success' => 2,
                'message' => 'Usuario no encontrado'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'nombre' => 'required|string|max:50',
            'email' => 'required|email|max:100|unique:administradores,email,' . $usuario->id,
            'password' => 'nullable|string|min:8',
            'rol' => 'required|integer|exists:roles,id',
            'estado' => 'required|boolean',
        ], [
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => 0,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()
            ], 422);
        }

        // =========================
        // REGLA: Usuario principal no se puede desactivar
        // =========================
        if ($usuario->id == 1 && $request->estado == false) {
            return response()->json([
                'success' => 0,
                'message' => 'El administrador principal no puede ser desactivado'
            ], 422);
        }

        $antes = [
            'nombre' => $usuario->nombre,
            'email'  => $usuario->email,
            'estado' => $usuario->estado,
            'rol'    => $usuario->getRoleNames()->first(),
        ];

        DB::beginTransaction();
        try {

            // Datos básicos
            $usuario->nombre = $request->nombre;
            $usuario->email = $request->email;
            $usuario->estado = filter_var($request->estado, FILTER_VALIDATE_BOOLEAN);

            if ($request->filled('password')) {
                $usuario->password = bcrypt($request->password);
            }

            $usuario->save();

            // Un usuario desactivado pierde sus sesiones abiertas
            if (!$usuario->estado) {
                $usuario->tokens()->delete();
            }

            // Rol
            $role = Role::find($request->rol);

            if (!$role) {
                DB::rollBack();
                return response()->json([
                    'success' => 0,
                    'message' => 'Rol no válido'
                ], 422);
            }

            $usuario->syncRoles([$role->name]);

            DB::commit();

            AuditoriaService::registrar('usuarios', 'actualizar', $usuario, $antes, [
                'nombre'          => $usuario->nombre,
                'email'           => $usuario->email,
                'estado'          => $usuario->estado,
                'rol'             => $role->name,
                'cambio_password' => $request->filled('password'),
            ]);

            return response()->json([
                'success' => 1,
                'message' => 'Administrador actualizado correctamente'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error actualizarAdministrador: ' . $e->getMessage());

            return response()->json([
                'success' => 0,
                'message' => 'Error interno del servidor'
            ], 500);
        }
    }

}
