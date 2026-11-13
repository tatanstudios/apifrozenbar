<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SeguridadTest extends TestCase
{
    // Rutas sin sesión: inicio de sesión y recuperación de contraseña (todas con límite de intentos)
    const PUBLICAS = ['api/login', 'api/login-pin', 'api/validate-reset-token', 'api/reset-password-confirm', 'api/admin/enviar/correo/password'];

    // Con sesión pero sin permiso específico, a propósito: cualquier usuario las necesita
    const SOLO_SESION = [
        'api/logout', 'api/me', 'api/datos',
        'api/me/pin',                  // cada quien cambia su propio PIN (pide su contraseña actual)
        'api/me/desbloquear',          // quita el bloqueo de pantalla con su propio PIN
        'api/me/cambiar-usuario',      // tablet bloqueada: el PIN de otro usuario abre su propia sesión
        'api/configuracion/negocio',   // lectura: moneda y datos del ticket (el POST pide permiso)
        'api/caja/catalogos',          // cajas y formas de pago para elegir la caja del dispositivo
        // Notificaciones propias: cada usuario solo ve, marca y borra las suyas
        'api/notificaciones', 'api/notificaciones/resumen', 'api/notificaciones/leer-todas',
        'api/notificaciones/{id}', 'api/notificaciones/{id}/leer',
    ];

    // ── Revisión de rutas ─────────────────────────────────────

    public function test_toda_ruta_de_la_api_pide_sesion_y_permiso(): void
    {
        $sinSesion = [];
        $sinPermiso = [];

        foreach (Route::getRoutes() as $ruta) {
            $uri = $ruta->uri();
            if (!str_starts_with($uri, 'api/') || in_array($uri, self::PUBLICAS, true)) {
                continue;
            }

            $middleware = implode(' ', $ruta->gatherMiddleware());
            if (!str_contains($middleware, 'auth:sanctum')) {
                $sinSesion[] = $uri;
                continue;
            }
            if (!str_contains($middleware, 'permission:') && !in_array($uri, self::SOLO_SESION, true)) {
                $sinPermiso[] = implode('|', $ruta->methods()) . ' ' . $uri;
            }
        }

        $this->assertSame([], $sinSesion, 'Rutas de la API sin sesión');
        $this->assertSame([], $sinPermiso, 'Rutas de la API sin permiso');
    }

    public function test_las_rutas_publicas_tienen_limite_de_intentos(): void
    {
        foreach (Route::getRoutes() as $ruta) {
            if (in_array($ruta->uri(), self::PUBLICAS, true)) {
                $this->assertStringContainsString('throttle', implode(' ', $ruta->gatherMiddleware()), $ruta->uri());
            }
        }
    }

    // ── Matriz de permisos ────────────────────────────────────

    public function test_matriz_agrupa_por_modulo_y_marca_lo_asignado(): void
    {
        $this->actuarComo('admin');
        $cajero = Role::where('name', 'cajero')->first();

        $modulos = collect($this->getJson("/api/admin/roles/{$cajero->id}/matriz")->assertOk()
            ->assertJsonPath('rol.nombre', 'cajero')
            ->assertJsonPath('rol.es_admin', false)
            ->json('modulos'))->keyBy('modulo');

        $this->assertSame('Panel de inicio', $modulos->keys()->first());
        $pos = collect($modulos['Punto de venta']['permisos'])->keyBy('nombre');
        $this->assertTrue($pos['pos.vender']['asignado']);
        $this->assertFalse($pos['pos.anular']['asignado']);
        $this->assertSame('Anular ventas', $pos['pos.anular']['descripcion']);
        // La explicación larga para el botón «?»
        $this->assertStringContainsString('cancelar un producto que ya se envió', $pos['pos.anular']['ayuda']);
    }

    public function test_cada_permiso_del_catalogo_tiene_su_explicacion(): void
    {
        $catalogo = array_keys(config('permisos.permisos'));
        $ayuda = config('permisos.ayuda');

        $this->assertSame([], array_values(array_diff($catalogo, array_keys($ayuda))), 'Permisos sin explicación');
        $this->assertSame([], array_values(array_diff(array_keys($ayuda), $catalogo)), 'Explicaciones de permisos que ya no existen');
        foreach ($ayuda as $permiso => $texto) {
            $this->assertGreaterThan(60, mb_strlen($texto), "La explicación de {$permiso} es muy corta");
        }
    }

    public function test_guardar_matriz_cambia_lo_que_puede_hacer_el_rol(): void
    {
        $this->actuarComo('admin');
        $cajero = Role::where('name', 'cajero')->first();
        $permisos = $cajero->permissions->pluck('name')->reject(fn ($p) => $p === 'gastos.registrar')->push('reportes.ver')->values()->all();

        $this->putJson("/api/admin/roles/{$cajero->id}/permisos", ['permisos' => $permisos])->assertOk();

        $registro = Auditoria::where('accion', 'permisos')->latest('id')->first();
        $this->assertSame(['reportes.ver'], $registro->datos_despues['agregados']);
        $this->assertSame(['gastos.registrar'], $registro->datos_antes['quitados']);

        // El cambio se aplica de inmediato
        $this->actuarComo('cajero');
        $this->getJson('/api/reportes/ventas_diarias')->assertOk();
        $this->postJson('/api/gastos', [])->assertStatus(403);
    }

    public function test_matriz_valida_y_protege_al_admin(): void
    {
        $this->actuarComo('admin');
        $admin = Role::where('name', 'admin')->first();
        $cajero = Role::where('name', 'cajero')->first();

        $this->getJson("/api/admin/roles/{$admin->id}/matriz")->assertJsonPath('rol.es_admin', true);
        $this->putJson("/api/admin/roles/{$admin->id}/permisos", ['permisos' => []])->assertStatus(422)
            ->assertJsonPath('message', 'El rol admin tiene acceso total: sus permisos no se editan');
        $this->putJson("/api/admin/roles/{$cajero->id}/permisos", ['permisos' => ['no.existe']])->assertStatus(422)
            ->assertJsonPath('message', 'Uno de los permisos no existe');

        $this->actuarComo('cajero');
        $this->putJson("/api/admin/roles/{$cajero->id}/permisos", ['permisos' => []])->assertStatus(403);
    }

    // ── Usuarios ──────────────────────────────────────────────

    public function test_listado_de_usuarios_muestra_ultimo_acceso(): void
    {
        $this->actuarComo('admin');
        $this->crearUsuario('mesero', ['nombre' => 'Rosa']);

        $rosa = collect($this->getJson('/api/admin/usuarios/tabla')->assertOk()->json())->firstWhere('nombre', 'Rosa');
        $this->assertArrayHasKey('ultimo_acceso', $rosa);
        $this->assertArrayNotHasKey('pin', $rosa);
    }

    // ── Auditoría ─────────────────────────────────────────────

    public function test_visor_de_auditoria_filtra(): void
    {
        $usuario = $this->actuarComo('admin');
        Auditoria::create(['usuario_id' => $usuario->id, 'modulo' => 'pos', 'accion' => 'anular_venta', 'descripcion' => 'Cliente arrepentido',
            'datos_antes' => ['estado' => 'completada'], 'datos_despues' => ['estado' => 'anulada']]);
        Auditoria::create(['usuario_id' => $usuario->id, 'modulo' => 'caja', 'accion' => 'abrir', 'descripcion' => 'Turno']);
        $viejo = Auditoria::create(['usuario_id' => $usuario->id, 'modulo' => 'pos', 'accion' => 'anular_venta', 'descripcion' => 'Viejo']);
        Auditoria::whereKey($viejo->id)->update(['created_at' => now()->subDays(30)]);

        $this->getJson('/api/auditoria?modulo=pos')->assertOk()
            ->assertJsonCount(1, 'registros')
            ->assertJsonPath('registros.0.antes.estado', 'completada')
            ->assertJsonPath('registros.0.despues.estado', 'anulada');

        $this->getJson('/api/auditoria?buscar=arrepentido')->assertJsonCount(1, 'registros');
        $this->getJson('/api/auditoria?desde=' . now()->subDays(40)->toDateString())->assertJsonPath('total', 3);
        $this->getJson('/api/auditoria/filtros')->assertOk()->assertJsonFragment(['caja']);
    }

    public function test_auditoria_requiere_permiso(): void
    {
        $this->actuarComo('encargado_inventario');
        $this->getJson('/api/auditoria')->assertStatus(403);
    }
}
