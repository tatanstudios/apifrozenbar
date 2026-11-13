<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        // 1️⃣ Roles y permisos
        $this->call(RolesAndPermissionsSeeder::class);

        // 2️⃣ Usuarios: administrador, mesero 1 y mesero 2
        $this->call(AdminSeeder::class);

        // 3️⃣ Configuración del negocio y correlativos
        $this->call(ConfiguracionNegocioSeeder::class);

        // 4️⃣ Unidades de medida con conversión
        $this->call(UnidadesMedidaSeeder::class);

        // 5) Caja principal y metodos de pago (sin caja no se puede abrir turno)
        $this->call(CajaSeeder::class);
    }
}
