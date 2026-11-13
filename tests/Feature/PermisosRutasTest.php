<?php

namespace Tests\Feature;

use Tests\TestCase;

class PermisosRutasTest extends TestCase
{
    public function test_rutas_de_productos_e_inventario_exigen_autenticacion(): void
    {
        $this->getJson('/api/productos/tabla')->assertStatus(401);
        $this->getJson('/api/inventario/stock')->assertStatus(401);
        $this->postJson('/api/inventario/entradas/registrar', [])->assertStatus(401);
        $this->postJson('/api/productos/registro', [])->assertStatus(401);
    }

    public function test_cajero_puede_ver_productos_pero_no_crearlos(): void
    {
        $this->actuarComo('cajero');

        $this->getJson('/api/productos/tabla')->assertOk();
        $this->postJson('/api/productos/registro', ['nombre' => 'Pan'])
            ->assertStatus(403)
            ->assertJsonPath('status', 'FORBIDDEN');
    }

    public function test_cajero_no_accede_a_roles_ni_usuarios(): void
    {
        $this->actuarComo('cajero');

        $this->getJson('/api/admin/roles/permisos-todos/tabla')->assertStatus(403);
        $this->getJson('/api/admin/usuarios/tabla')->assertStatus(403);
        $this->postJson('/api/admin/permisos/nuevo-usuario', [])->assertStatus(403);
    }

    public function test_encargado_de_inventario_gestiona_productos_pero_no_usuarios(): void
    {
        $this->actuarComo('encargado_inventario');

        $this->getJson('/api/admin/categorias/tabla')->assertOk();
        $this->getJson('/api/inventario/entradas')->assertOk();
        $this->getJson('/api/admin/usuarios/tabla')->assertStatus(403);
        $this->getJson('/api/admin/impresora/index')->assertStatus(403);
    }

    public function test_admin_tiene_acceso_total(): void
    {
        $this->actuarComo('admin');

        $this->getJson('/api/admin/usuarios/tabla')->assertOk();
        $this->getJson('/api/admin/roles/permisos-todos/tabla')->assertOk();
        $this->getJson('/api/admin/impresora/index')->assertOk();
        $this->getJson('/api/productos/tabla')->assertOk();
    }

    public function test_me_devuelve_permisos_del_rol(): void
    {
        $this->actuarComo('cajero');

        $permisos = $this->getJson('/api/me')->assertOk()->json('permissions');

        $this->assertContains('pos.vender', $permisos);
        $this->assertNotContains('usuarios.gestionar', $permisos);
    }
}
