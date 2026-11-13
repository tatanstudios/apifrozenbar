<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ENTRADA DE INVENTARIO DETALLE
     */
    public function up(): void
    {
        Schema::create('entradas_inventario_detalle', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entrada_id')->constrained('entradas_inventario')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos');
            $table->decimal('cantidad', 10, 4);
            $table->decimal('stock_anterior', 10, 4);
            $table->decimal('stock_resultante', 10, 4);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('entradas_inventario_detalle');
    }
};
