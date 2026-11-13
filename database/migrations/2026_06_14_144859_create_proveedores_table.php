<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PROVEEDORES
     */
    public function up(): void
    {
        Schema::create('proveedores', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 200);
            $table->string('nit', 30)->nullable()->unique();
            $table->string('nrc', 30)->nullable();
            $table->string('contacto', 100)->nullable();
            $table->string('telefono', 30)->nullable();
            $table->string('correo', 100)->nullable();
            $table->string('direccion', 300)->nullable();
            $table->unsignedSmallInteger('dias_credito')->default(0); // 0 = de contado
            // Lo que se le debe hoy: se mantiene en las mismas transacciones de compras y pagos
            $table->decimal('saldo', 12, 2)->default(0);
            $table->text('notas')->nullable();
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
        Schema::dropIfExists('proveedores');
    }
};
