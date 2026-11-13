<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cuántas unidades se eligen en total en un grupo («Tipo de leche: al menos 1, máximo 2»),
     * contando cada vez que se repite una opción. minimo 0 = opcional; maximo null = sin tope.
     * «obligatorio» se conserva igual a minimo > 0 para las versiones anteriores de la app.
     */
    public function up(): void
    {
        Schema::table('grupos_modificadores', function (Blueprint $table) {
            $table->unsignedTinyInteger('minimo')->default(0)->after('multiple');
            $table->unsignedTinyInteger('maximo')->nullable()->after('minimo');
        });

        DB::table('grupos_modificadores')->where('obligatorio', true)->update(['minimo' => 1]);
    }

    public function down(): void
    {
        Schema::table('grupos_modificadores', function (Blueprint $table) {
            $table->dropColumn(['minimo', 'maximo']);
        });
    }
};
