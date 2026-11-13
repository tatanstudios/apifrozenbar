<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MOVIMIENTOS DE LA CAJA
     */
    public function up(): void
    {
        Schema::create('movimientos_caja', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique(); // idempotencia: el mismo movimiento no se registra dos veces
            $table->foreignId('turno_id')->constrained('turnos_caja');
            // Entran: venta, ingreso, abono · Salen: egreso, devolucion, gasto
            $table->enum('tipo', ['venta', 'ingreso', 'egreso', 'devolucion', 'abono', 'gasto', 'anticipo', 'pago_proveedor']);






            $table->foreignId('metodo_pago_id')->constrained('metodos_pago');
            $table->decimal('monto', 12, 2);   // siempre positivo; el tipo da el sentido
            $table->string('concepto', 300);
            $table->string('referencia', 100)->nullable();       // voucher, n.º de transferencia
            $table->string('documento_tipo', 50)->nullable();    // venta, devolucion, abono, gasto...
            $table->unsignedBigInteger('documento_id')->nullable();
            $table->foreignId('usuario_id')->constrained('administradores');

            // Los movimientos no se borran: se anulan con motivo
            $table->boolean('anulado')->default(false);
            $table->foreignId('anulado_por')->nullable()->constrained('administradores');
            $table->timestamp('anulado_at')->nullable();
            $table->string('motivo_anulacion', 300)->nullable();
            $table->timestamps();

            $table->index(['turno_id', 'tipo']);
            $table->index(['documento_tipo', 'documento_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('movimientos_caja');
    }
};
