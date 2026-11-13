<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * LISTA DE PEDIDOS
     */
    public function up(): void
    {
        // Precuentas (ventas en espera) y, desde la Fase 6, pedidos especiales
        Schema::create('pedidos', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('numero', 20)->unique();
            $table->enum('tipo', ['precuenta', 'especial'])->default('precuenta');
            $table->string('nombre', 100);                        // "Mesa 2", "Sra. Ana", "Encargo evento"
            $table->string('telefono', 30)->nullable();           // contacto del encargo
            $table->foreignId('cliente_id')->nullable()           // FK con el módulo de Clientes
            ->constrained('clientes')->nullOnDelete();
            $table->foreignId('caja_id')->nullable()->constrained('cajas')->nullOnDelete();
            $table->foreignId('usuario_id')->constrained('administradores');
            $table->foreignId('turno_id')->nullable()->constrained('turnos_caja'); // turno abierto cuando se registró
            $table->enum('estado', ['abierto', 'cobrado', 'cancelado'])->default('abierto');
            $table->decimal('total', 12, 2)->default(0);          // referencia; al cobrar se recalcula
            $table->decimal('anticipo', 12, 2)->default(0);
            $table->dateTime('fecha_entrega')->nullable();
            $table->unsignedBigInteger('venta_id')->nullable();
            $table->string('observaciones', 500)->nullable();
            $table->foreignId('cancelado_por')->nullable()->constrained('administradores');
            $table->string('motivo_cancelacion', 300)->nullable();
            $table->timestamps();

            $table->index(['tipo', 'fecha_entrega']);
            $table->index(['estado', 'tipo']);
        });

        Schema::create('pedidos_detalle', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_id')->constrained('pedidos')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos');
            $table->string('nombre_producto', 300);
            $table->decimal('cantidad', 12, 4);
            $table->decimal('precio_unitario', 12, 2);
            $table->enum('descuento_tipo', ['porcentaje', 'monto'])->nullable();
            $table->decimal('descuento_valor', 12, 2)->default(0);
            $table->string('notas', 200)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pedidos_detalle');
        Schema::dropIfExists('pedidos');
    }
};
