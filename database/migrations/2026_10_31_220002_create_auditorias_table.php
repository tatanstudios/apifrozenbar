<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * AUDITORIA, REGISTRO DE ACCIONES
     */
    public function up(): void
    {
        // Registro de actividad: quién hizo qué, cuándo y desde dónde
        Schema::create('auditorias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->nullable()->constrained('administradores')->nullOnDelete();
            $table->string('modulo', 50);   // auth, inventario, productos, usuarios, configuracion...
            $table->string('accion', 50);   // login, crear, actualizar, anular, ajustar...
            $table->string('entidad_tipo', 50)->nullable();
            $table->unsignedBigInteger('entidad_id')->nullable();
            $table->string('descripcion', 500)->nullable();
            $table->json('datos_antes')->nullable();
            $table->json('datos_despues')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 300)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['modulo', 'accion']);
            $table->index(['entidad_tipo', 'entidad_id']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('auditorias');
    }
};
