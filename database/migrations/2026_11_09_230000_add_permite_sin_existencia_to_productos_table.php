<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Producto que lleva inventario pero se puede vender aunque no haya existencia
     * (Coca-Cola, golosinas): queda en negativo aunque el negocio no permita stock negativo.
     */
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->boolean('permite_sin_existencia')->default(false)->after('permite_fraccion');
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->dropColumn('permite_sin_existencia');
        });
    }
};
