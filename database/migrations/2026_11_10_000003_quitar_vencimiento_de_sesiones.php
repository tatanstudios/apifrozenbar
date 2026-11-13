<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Las sesiones ya no vencen (antes la web vencía a las 16 horas): las que estén
     * abiertas dejan de tener fecha de vencimiento y duran hasta «Cerrar sesión».
     */
    public function up(): void
    {
        DB::table('personal_access_tokens')->whereNotNull('expires_at')->update(['expires_at' => null]);
    }

    public function down(): void
    {
        // No hay forma de saber qué vencimiento tenía cada sesión
    }
};
