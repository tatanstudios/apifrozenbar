<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cuántas veces se puede pedir una opción en un mismo producto (Huevo extra: hasta 3).
     * 1 = se marca o no, como hasta ahora.
     */
    public function up(): void
    {
        Schema::table('modificadores', function (Blueprint $table) {
            $table->unsignedTinyInteger('cantidad_maxima')->default(1)->after('precio');
        });
    }

    public function down(): void
    {
        Schema::table('modificadores', function (Blueprint $table) {
            $table->dropColumn('cantidad_maxima');
        });
    }
};
