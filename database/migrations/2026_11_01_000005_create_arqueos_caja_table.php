<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ARQUEOS DE CAJA
     */
    public function up(): void
    {
        // Conteos de efectivo: parciales durante el turno y el del cierre
        Schema::create('arqueos_caja', function (Blueprint $table) {
            $table->id();
            $table->foreignId('turno_id')->constrained('turnos_caja');
            $table->enum('tipo', ['parcial', 'cierre']);
            $table->json('denominaciones')->nullable(); // {"20": 3, "0.25": 8, ...}
            $table->decimal('total_contado', 12, 2);
            $table->decimal('efectivo_esperado', 12, 2);
            $table->decimal('diferencia', 12, 2);       // contado - esperado
            $table->string('observaciones', 500)->nullable();
            $table->foreignId('usuario_id')->constrained('administradores');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('arqueos_caja');
    }
};
