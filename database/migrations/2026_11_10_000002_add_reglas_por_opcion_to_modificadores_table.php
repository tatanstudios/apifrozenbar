<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * «Una sola» y activo pasan del grupo a cada opción; el grupo conserva «obligatorio»
     * con otro sentido: hay que elegir al menos una de sus opciones (Huevos: cómo van).
     * Cada opción además puede ser obligatoria:
     * - obligatorio: la opción siempre va en el producto (se puede subir hasta su máximo, no quitar)
     * - exclusiva: al elegirla se quitan las demás del grupo («Sin azúcar» quita «Azúcar extra»)
     * - eliminada: se quitó del grupo; no se borra porque hay órdenes que la citan.
     *   Hasta ahora eso era «activo = false»; desde aquí activo es un interruptor de cada opción.
     */
    public function up(): void
    {
        Schema::table('modificadores', function (Blueprint $table) {
            $table->boolean('obligatorio')->default(false)->after('cantidad_maxima');
            $table->boolean('exclusiva')->default(false)->after('obligatorio');
            $table->boolean('eliminada')->default(false)->after('activo');
        });

        // Lo inactivo de antes son opciones quitadas del grupo
        DB::table('modificadores')->where('activo', false)->update(['eliminada' => true]);
        // Grupo de una sola opción: cada opción se vuelve «una sola»
        $unaSola = DB::table('grupos_modificadores')->where('multiple', false)->pluck('id');
        DB::table('modificadores')->whereIn('grupo_id', $unaSola)->update(['exclusiva' => true]);
        // Grupo apagado: sus opciones quedan apagadas
        $apagados = DB::table('grupos_modificadores')->where('activo', false)->pluck('id');
        DB::table('modificadores')->whereIn('grupo_id', $apagados)->update(['activo' => false]);
        // «Una sola» y activo ya no son del grupo; «obligatorio» se queda (elegir al menos una)
        DB::table('grupos_modificadores')->update(['multiple' => true, 'activo' => true]);
    }

    public function down(): void
    {
        Schema::table('modificadores', function (Blueprint $table) {
            $table->dropColumn(['obligatorio', 'exclusiva', 'eliminada']);
        });
    }
};
