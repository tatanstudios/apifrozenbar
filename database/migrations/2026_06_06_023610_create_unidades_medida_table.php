<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * UNIDADES DE MEDIDA
     */
    public function up(): void
    {
        Schema::create('unidades_medida', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100);
            $table->string('abreviatura', 10)->nullable();
            // Unidades del mismo tipo se pueden convertir entre sí
            $table->enum('tipo', ['unidad', 'masa', 'volumen'])->default('unidad');
            // Equivalencia en la unidad base del tipo: unidad → 1, masa → gramos, volumen → mililitros
            $table->decimal('factor_base', 16, 6)->default(1);
            $table->boolean('estado')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('unidades_medida');
    }
};
