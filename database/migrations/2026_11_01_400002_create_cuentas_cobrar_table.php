<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TODAS LAS CUENTAS POR COBRAR
     */
    public function up(): void
    {
        // Una cuenta por cada venta (o parte de venta) hecha al crédito
        Schema::create('cuentas_cobrar', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes');
            $table->foreignId('venta_id')->nullable()->unique()->constrained('ventas');
            $table->decimal('monto', 12, 2);
            $table->decimal('saldo', 12, 2);
            $table->date('fecha_vencimiento');
            $table->enum('estado', ['pendiente', 'pagada', 'anulada'])->default('pendiente');
            $table->timestamps();

            $table->index(['cliente_id', 'estado']);
            $table->index(['estado', 'fecha_vencimiento']);
        });

        Schema::create('abonos', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('numero', 20)->unique(); // AB-000001
            $table->foreignId('cliente_id')->constrained('clientes');
            $table->foreignId('turno_id')->constrained('turnos_caja');
            $table->foreignId('metodo_pago_id')->constrained('metodos_pago');
            $table->decimal('monto', 12, 2);
            $table->string('referencia', 100)->nullable();
            $table->foreignId('usuario_id')->constrained('administradores');
            $table->boolean('anulado')->default(false);
            $table->foreignId('anulado_por')->nullable()->constrained('administradores');
            $table->timestamp('anulado_at')->nullable();
            $table->string('motivo_anulacion', 300)->nullable();
            $table->timestamps();
        });

        // A qué cuentas se aplicó cada abono (de la más antigua a la más reciente)
        Schema::create('abonos_detalle', function (Blueprint $table) {
            $table->id();
            $table->foreignId('abono_id')->constrained('abonos')->cascadeOnDelete();
            $table->foreignId('cuenta_cobrar_id')->constrained('cuentas_cobrar');
            $table->decimal('monto', 12, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('abonos_detalle');
        Schema::dropIfExists('abonos');
        Schema::dropIfExists('cuentas_cobrar');
    }
};
