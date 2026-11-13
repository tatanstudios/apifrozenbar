<?php

namespace Tests\Feature;

use App\Services\ManualService;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ManualesTest extends TestCase
{
    public function test_los_roles_base_ven_los_manuales(): void
    {
        Sanctum::actingAs($this->crearUsuario('cajero'), ['*'], 'sanctum');

        $r = $this->getJson('/api/manuales')->assertOk();
        $this->assertNotEmpty($r->json('modulos'));
        $this->assertNotEmpty($r->json('modulos.0.manuales.0.secciones'));
    }

    public function test_sin_el_permiso_no_se_ven(): void
    {
        Role::firstOrCreate(['name' => 'sin_manuales', 'guard_name' => 'api']);
        Sanctum::actingAs($this->crearUsuario('sin_manuales'), ['*'], 'sanctum');

        $this->getJson('/api/manuales')->assertForbidden();
    }

    // El contenido es un JSON escrito a mano: ids únicos, permisos que existen y relacionados válidos
    public function test_el_contenido_es_coherente(): void
    {
        $datos = json_decode(file_get_contents(resource_path('manuales/manuales.json')), true);
        $this->assertSame(JSON_ERROR_NONE, json_last_error());

        $permisos = array_keys(config('permisos.permisos'));
        $manuales = collect($datos['modulos'])->flatMap(fn ($m) => $m['manuales']);
        $ids = $manuales->pluck('id');
        $this->assertSame($ids->count(), $ids->unique()->count(), 'Hay manuales con el mismo id');

        foreach ($manuales as $m) {
            $this->assertNotEmpty($m['titulo']);
            $this->assertNotEmpty($m['resumen']);
            $this->assertNotEmpty($m['secciones'], "{$m['id']} no tiene secciones");
            foreach ($m['permisos'] as $p) {
                $this->assertContains($p, $permisos, "{$m['id']}: el permiso {$p} no existe");
            }
            foreach ($m['relacionados'] ?? [] as $rel) {
                $this->assertContains($rel, $ids, "{$m['id']}: el relacionado {$rel} no existe");
            }
            foreach ($m['secciones'] as $s) {
                $this->assertTrue(isset($s['pasos']) || isset($s['puntos']) || isset($s['texto']), "{$m['id']}: sección vacía");
            }
        }
    }

    private function idsVisibles(): array
    {
        return collect($this->getJson('/api/manuales')->assertOk()->json('modulos'))
            ->flatMap(fn ($m) => collect($m['manuales'])->pluck('id'))->all();
    }

    public function test_el_seeder_solo_crea_admin_y_mesero(): void
    {
        $this->assertSame(['mesero'], array_keys(config('permisos.roles')));
        $this->assertSame(0, \Spatie\Permission\Models\Permission::whereIn('name', ['admin.sidebar.roles.y.permisos', 'editor.sidebar.dashboard'])->count());
    }

    public function test_el_mesero_ve_de_entrada_los_manuales_de_sus_modulos(): void
    {
        $this->actuarComo('mesero');
        $ids = $this->idsVisibles();

        $this->assertContains('mermas', $ids);
        $this->assertContains('como-funciona', $ids);        // no pide permiso
        $this->assertNotContains('reportes', $ids);           // el mesero no tiene reportes
        $this->assertNotContains('roles-permisos', $ids);
    }

    public function test_el_admin_elige_que_manuales_ve_el_mesero(): void
    {
        $this->actuarComo('admin');
        $mesero = \Spatie\Permission\Models\Role::findByName('mesero', 'api');

        $r = $this->getJson('/api/manuales/permisos')->assertOk();
        $this->assertContains('mesero', collect($r->json('roles'))->pluck('nombre'));
        $this->assertNotContains('admin', collect($r->json('roles'))->pluck('nombre'));

        $this->putJson("/api/manuales/permisos/{$mesero->id}", ['manuales' => ['venta-rapida', 'reportes']])->assertOk();
        $this->putJson("/api/manuales/permisos/{$mesero->id}", ['manuales' => ['no-existe']])->assertStatus(422);

        $this->actuarComo('mesero');
        $this->assertEqualsCanonicalizing(['venta-rapida', 'reportes'], $this->idsVisibles());

        // El admin siempre ve todos
        $this->actuarComo('admin');
        $this->assertCount(ManualService::manuales()->count(), $this->idsVisibles());
    }

    public function test_la_matriz_de_permisos_no_muestra_ni_borra_los_manuales(): void
    {
        $this->actuarComo('admin');
        $mesero = \Spatie\Permission\Models\Role::findByName('mesero', 'api');
        $this->putJson("/api/manuales/permisos/{$mesero->id}", ['manuales' => ['mermas']])->assertOk();

        $matriz = $this->getJson("/api/admin/roles/{$mesero->id}/matriz")->assertOk();
        $nombres = collect($matriz->json('modulos'))->flatMap(fn ($m) => collect($m['permisos'])->pluck('nombre'));
        $this->assertFalse($nombres->contains(fn ($n) => str_starts_with($n, ManualService::PREFIJO)));

        $this->putJson("/api/admin/roles/{$mesero->id}/permisos", ['permisos' => ['pos.vender', 'manuales.ver']])->assertOk();
        $this->assertTrue($mesero->fresh()->hasPermissionTo('manual.mermas'));
        $this->assertFalse($mesero->fresh()->hasPermissionTo('caja.abrir'));
    }

    public function test_solo_quien_gestiona_roles_cambia_los_manuales(): void
    {
        $this->actuarComo('mesero');
        $this->getJson('/api/manuales/permisos')->assertForbidden();
    }
}
