<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * REGISTRO DE INVENTARIO
     */
    public function up(): void
    {
        Schema::create('entradas_inventario', function (Blueprint $table) {
            $table->id();
            $table->date('fecha');
            $table->enum('tipo', ['entrada', 'salida', 'ajuste', 'produccion', 'venta']);
            $table->string('descripcion', 500)->nullable();
            $table->foreignId('usuario_id')->nullable()->constrained('administradores')->nullOnDelete();
            // Quién autorizó una salida o un ajuste a la baja de un insumo de receta
            $table->foreignId('autorizado_por')->nullable()->constrained('administradores');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('entradas_inventario');
    }
};
