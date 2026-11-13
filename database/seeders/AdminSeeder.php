<?php

namespace Database\Seeders;

use App\Models\Administrador;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Correo y clave del administrador salen del .env (ADMIN_EMAIL / ADMIN_PASSWORD).
        // Los valores por defecto son solo para desarrollo local: en producción hay que definirlos.
        Administrador::firstOrCreate(['email' => env('ADMIN_EMAIL') ?: 'tatangamess@gmail.test'], [
            'nombre' => 'Administrador',
            'password' => Hash::make(env('ADMIN_PASSWORD') ?: '1234'),
            'estado' => true,
        ])->assignRole('admin');

        // Los dos meseros: cada uno entra con su usuario, abre su turno y lo cierra
        foreach ([1, 2] as $n) {
            Administrador::firstOrCreate(['email' => "mesero{$n}@thefrozenbar.test"], [
                'nombre' => "Mesero {$n}",
                'password' => Hash::make('1234'),
                'estado' => true,
            ])->assignRole('mesero');
        }
    }
}
