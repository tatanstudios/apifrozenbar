<?php

namespace Tests;

use App\Models\Administrador;
use App\Models\TurnoCaja;
use App\Services\ManualService;
use Spatie\Permission\Models\Role;
use Database\Seeders\ConfiguracionNegocioSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Cada empleado vende en su propio turno (CajaService::exigirTurnoPropio).
     * Las pruebas que no tratan de eso abren el turno en setUp con otro usuario:
     * con esto en true, el turno abierto pasa a quien actúa en cada actuarComo().
     */
    protected bool $turnoSigueAlUsuario = false;

    /**
     * El negocio solo usa admin y mesero (los crea el seeder). Estos roles existen solo en
     * las pruebas, para comprobar permisos con conjuntos más chicos que el del mesero.
     */
    protected const ROLES_DE_PRUEBA = [
        'cajero' => [
            'dashboard.ver',
            'pos.vender', 'pos.descuento', 'pos.historial', 'pos.precuentas',
            'caja.abrir', 'caja.cerrar', 'caja.movimientos', 'caja.arqueo',
            'productos.ver', 'inventario.ver',
            'clientes.ver', 'clientes.gestionar', 'cuentas_cobrar.gestionar', 'pedidos.gestionar',
            'gastos.registrar',
            'manuales.ver',
        ],
        'encargado_inventario' => [
            'dashboard.ver',
            'productos.ver', 'productos.gestionar', 'catalogos.gestionar',
            'inventario.ver', 'inventario.registrar', 'inventario.ajustar', 'inventario.kardex',
            'proveedores.gestionar', 'compras.ver', 'compras.gestionar',
            'recetas.gestionar', 'produccion.ver', 'produccion.registrar', 'mermas.registrar',
            'reportes.ver',
            'manuales.ver',
        ],
        'editor' => [
            'dashboard.ver',
            'manuales.ver',
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesAndPermissionsSeeder::class, ConfiguracionNegocioSeeder::class]);

        foreach (self::ROLES_DE_PRUEBA as $nombre => $permisos) {
            $rol = Role::create(['name' => $nombre, 'guard_name' => 'api']);
            $rol->givePermissionTo($permisos);
            ManualService::darSegunModulos($rol->load('permissions'));
        }
    }

    /**
     * Crea un usuario activo con el rol indicado (admin, cajero, encargado_inventario, editor).
     */
    protected function crearUsuario(string $rol = 'admin', array $atributos = []): Administrador
    {
        static $n = 0;
        $n++;

        $usuario = Administrador::create(array_merge([
            'nombre'   => "Usuario {$n}",
            'email'    => "usuario{$n}@test.com",
            'password' => 'secreto123',
            'estado'   => true,
        ], $atributos));

        $usuario->assignRole($rol);

        return $usuario;
    }

    /**
     * Autentica las peticiones siguientes con un usuario del rol indicado.
     */
    protected function actuarComo(string $rol = 'admin', array $atributos = []): Administrador
    {
        $usuario = $this->crearUsuario($rol, $atributos);
        Sanctum::actingAs($usuario, ['*'], 'sanctum');

        if ($this->turnoSigueAlUsuario) {
            TurnoCaja::where('estado', 'abierto')->update(['usuario_apertura_id' => $usuario->id]);
        }

        return $usuario;
    }
}
