<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TURNO DE CADA CAJA
     */
    public function up(): void
    {
        Schema::create('turnos_caja', function (Blueprint $table) {
            $table->id();
            $table->foreignId('caja_id')->constrained('cajas');
            $table->foreignId('usuario_apertura_id')->constrained('administradores');
            $table->foreignId('usuario_cierre_id')->nullable()->constrained('administradores');
            $table->enum('estado', ['abierto', 'cerrado'])->default('abierto');
            $table->timestamp('abierto_at');
            $table->timestamp('cerrado_at')->nullable();

            $table->decimal('fondo_inicial', 12, 2)->default(0);

            // Se llenan al cerrar (foto del turno)
            $table->decimal('total_ventas', 12, 2)->default(0);
            $table->decimal('total_ingresos', 12, 2)->default(0);
            $table->decimal('total_egresos', 12, 2)->default(0);
            $table->decimal('efectivo_esperado', 12, 2)->nullable();
            $table->decimal('efectivo_contado', 12, 2)->nullable();
            $table->decimal('diferencia', 12, 2)->nullable();     // contado - esperado
            $table->json('resumen')->nullable();                  // por método y por empleado

            $table->string('observaciones_apertura', 500)->nullable();
            $table->string('observaciones_cierre', 500)->nullable();
            $table->timestamps();

            // Garantía en la base de datos: una sola caja con turno abierto a la vez
            $table->unsignedBigInteger('caja_abierta_id')
                ->nullable()
                ->storedAs("IF(estado = 'abierto', caja_id, NULL)")
                ->unique();

            $table->index(['estado', 'abierto_at']);
            $table->index(['estado', 'cerrado_at']);
        });

        // Inventario se crea antes que los turnos: aquí se le agrega el turno en que se registró
        foreach (['entradas_inventario', 'movimientos_inventario'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->foreignId('turno_id')->nullable()->after('usuario_id')->constrained('turnos_caja');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['entradas_inventario', 'movimientos_inventario'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->dropConstrainedForeignId('turno_id');
            });
        }
        Schema::dropIfExists('turnos_caja');
    }
};
