<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * REGISTRO DE VENTAS
     */
    public function up(): void
    {
        Schema::create('ventas', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();                       // generado en el dispositivo: evita duplicados
            $table->string('numero', 20)->unique();               // T-000001
            $table->string('numero_provisional', 30)->nullable();
            $table->foreignId('turno_id')->constrained('turnos_caja');
            $table->foreignId('caja_id')->constrained('cajas');
            $table->foreignId('usuario_id')->constrained('administradores');
            $table->foreignId('cliente_id')->nullable()           // FK con el módulo de Clientes
            ->constrained('clientes')->nullOnDelete();
            $table->foreignId('pedido_id')->nullable()->constrained('pedidos')->nullOnDelete();
            $table->enum('estado', ['completada', 'anulada', 'devuelta'])->default('completada');

            // Importes (todo calculado en el servidor)
            $table->decimal('subtotal', 12, 2);           // suma de líneas ya con su descuento
            $table->decimal('descuento_lineas', 12, 2)->default(0);
            $table->decimal('descuento_global', 12, 2)->default(0);
            $table->decimal('descuento_total', 12, 2)->default(0);
            $table->decimal('base_imponible', 12, 2);
            $table->decimal('impuesto', 12, 2);
            $table->decimal('total', 12, 2);
            $table->decimal('costo_total', 14, 4)->default(0);
            $table->decimal('cambio', 12, 2)->default(0);
            // Anticipo de un pedido especial descontado al cobrarlo (ya había entrado a caja)
            $table->decimal('anticipo_aplicado', 12, 2)->default(0);

            $table->enum('origen', ['online', 'offline'])->default('online');
            $table->dateTime('fecha_venta');               // hora del dispositivo al vender
            $table->timestamp('sincronizada_at')->nullable();
            // Diferencias detectadas al sincronizar (precio cambiado, stock insuficiente...)
            $table->json('conflictos')->nullable();
            $table->foreignId('descuento_autorizado_por')->nullable()->constrained('administradores');
            $table->string('observaciones', 300)->nullable();

            // Anulación (no se borra nada)
            $table->foreignId('anulada_por')->nullable()->constrained('administradores');
            $table->foreignId('anulacion_autorizada_por')->nullable()->constrained('administradores');
            $table->timestamp('anulada_at')->nullable();
            $table->string('motivo_anulacion', 300)->nullable();
            $table->timestamps();

            $table->index('fecha_venta');
            $table->index(['estado', 'fecha_venta']);
            $table->index(['turno_id', 'estado']);
            $table->index('origen');
        });

        Schema::create('ventas_detalle', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venta_id')->constrained('ventas')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos');
            // Copia del producto al vender: el historial no cambia si luego cambia el producto
            $table->string('nombre_producto', 300);
            // Extras, cambios de receta y nota de la venta rápida, uno por renglón en la comanda
            $table->json('extras')->nullable();
            $table->string('unidad', 10)->nullable();
            $table->decimal('cantidad', 12, 4);
            $table->decimal('precio_unitario', 12, 2);
            $table->enum('descuento_tipo', ['porcentaje', 'monto'])->nullable();
            $table->decimal('descuento_valor', 12, 2)->default(0);
            $table->decimal('descuento', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2);
            $table->decimal('costo_unitario', 12, 4)->default(0);
            $table->boolean('consumo_interno')->default(false); // plato del personal a $0.00
            $table->decimal('cantidad_devuelta', 12, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('ventas_pagos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venta_id')->constrained('ventas')->cascadeOnDelete();
            $table->foreignId('metodo_pago_id')->constrained('metodos_pago');
            $table->decimal('monto', 12, 2);                      // lo que se aplica a la venta
            $table->decimal('monto_recibido', 12, 2)->nullable(); // efectivo entregado por el cliente
            $table->decimal('cambio', 12, 2)->default(0);
            $table->string('referencia', 100)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ventas_pagos');
        Schema::dropIfExists('ventas_detalle');
        Schema::dropIfExists('ventas');
    }
};
