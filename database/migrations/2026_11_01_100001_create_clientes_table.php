<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CLIENTES
     */
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 200);
            $table->string('documento', 30)->nullable()->unique(); // DUI o NIT
            $table->string('telefono', 30)->nullable();
            $table->string('correo', 100)->nullable();
            $table->string('direccion', 300)->nullable();
            // Crédito: 0 = no se le vende al crédito
            $table->decimal('limite_credito', 12, 2)->default(0);
            $table->unsignedSmallInteger('dias_credito')->default(30);
            // Lo que debe hoy: se mantiene en las mismas transacciones de ventas y abonos
            $table->decimal('saldo', 12, 2)->default(0);
            $table->string('notas', 500)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('nombre');
        });


    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clientes');
    }
};
