<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * INVENTARIO
     */
    public function up(): void
    {
        Schema::create('inventario', function (Blueprint $table) {
            $table->id();
            // FK productos
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();

            // maximo 999,999.9999 unidades
            $table->decimal('stock_actual', 10, 4)->default(0);
            $table->decimal('stock_minimo', 10, 4)->default(0);
            $table->timestamps();

            $table->unique('producto_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventario');
    }
};
