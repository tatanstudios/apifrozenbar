<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * REGISTRO DE DEVOLUCIONES
     */
    public function up(): void
    {
        Schema::create('devoluciones', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('numero', 20)->unique();             // DEV-000001
            $table->foreignId('venta_id')->constrained('ventas');
            $table->foreignId('turno_id')->constrained('turnos_caja'); // turno del que sale el reembolso
            $table->foreignId('usuario_id')->constrained('administradores');
            $table->foreignId('autorizado_por')->nullable()->constrained('administradores');
            $table->foreignId('metodo_pago_id')->constrained('metodos_pago');
            $table->string('motivo', 300);
            $table->decimal('total', 12, 2);
            $table->timestamps();
            $table->index('created_at');
        });

        Schema::create('devoluciones_detalle', function (Blueprint $table) {
            $table->id();
            $table->foreignId('devolucion_id')->constrained('devoluciones')->cascadeOnDelete();
            $table->foreignId('venta_detalle_id')->constrained('ventas_detalle');
            $table->foreignId('producto_id')->constrained('productos');
            $table->decimal('cantidad', 12, 4);
            $table->decimal('precio_unitario', 12, 4); // precio efectivo (ya con descuentos)
            $table->decimal('subtotal', 12, 2);
            $table->boolean('reingresa_inventario')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('devoluciones_detalle');
        Schema::dropIfExists('devoluciones');
    }
};
