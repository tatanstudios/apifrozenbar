<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * NOTIFICACIONES POR USUARIO (stock bajo, stock negativo...)
     */
    public function up(): void
    {
        Schema::create('notificaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('administradores')->cascadeOnDelete();
            $table->string('tipo', 40);          // stock_bajo, stock_negativo
            $table->string('titulo', 150);
            $table->string('mensaje', 500)->nullable();
            $table->foreignId('producto_id')->nullable()->constrained('productos')->cascadeOnDelete();
            $table->string('enlace', 200)->nullable(); // ruta del panel a la que lleva
            $table->timestamp('leida_at')->nullable();
            $table->timestamps();

            $table->index(['usuario_id', 'leida_at']);
            $table->index(['usuario_id', 'tipo', 'producto_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notificaciones');
    }
};
