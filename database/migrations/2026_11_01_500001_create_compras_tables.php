<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * COMPRAS
     */
    public function up(): void
    {
        Schema::create('ordenes_compra', function (Blueprint $table) {
            $table->id();
            $table->string('numero', 20)->unique(); // OC-000001
            $table->foreignId('proveedor_id')->constrained('proveedores');
            $table->date('fecha');
            $table->date('fecha_esperada')->nullable();
            $table->enum('estado', ['borrador', 'enviada', 'parcial', 'recibida', 'cancelada'])->default('borrador');
            $table->decimal('total', 12, 2)->default(0);
            $table->string('observaciones', 500)->nullable();
            $table->foreignId('usuario_id')->constrained('administradores');
            $table->foreignId('turno_id')->nullable()->constrained('turnos_caja'); // turno abierto cuando se registró
            $table->foreignId('cancelada_por')->nullable()->constrained('administradores');
            $table->string('motivo_cancelacion', 300)->nullable();
            $table->timestamps();

            $table->index(['estado', 'fecha']);
        });

        Schema::create('ordenes_compra_detalle', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orden_id')->constrained('ordenes_compra')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos');
            $table->foreignId('unidad_medida_id')->constrained('unidades_medida'); // unidad en que se compra
            $table->decimal('cantidad', 12, 4);
            $table->decimal('cantidad_recibida', 12, 4)->default(0);
            $table->decimal('costo_unitario', 12, 4)->default(0);              // por unidad de compra
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('compras', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('numero', 20)->unique(); // C-000001
            $table->foreignId('proveedor_id')->constrained('proveedores');
            $table->foreignId('orden_compra_id')->nullable()->constrained('ordenes_compra');
            $table->date('fecha');
            $table->enum('tipo_documento', ['factura', 'ccf', 'ticket', 'otro'])->default('factura');
            $table->string('numero_documento', 50)->nullable();
            $table->enum('condicion', ['contado', 'credito']);
            $table->decimal('subtotal', 12, 2);
            $table->decimal('impuesto', 12, 2)->default(0);   // IVA del documento (crédito fiscal)
            $table->decimal('total', 12, 2);
            $table->enum('estado', ['registrada', 'anulada'])->default('registrada');
            $table->string('observaciones', 500)->nullable();
            $table->foreignId('usuario_id')->constrained('administradores');
            $table->foreignId('turno_id')->nullable()->constrained('turnos_caja'); // turno abierto cuando se registró
            $table->foreignId('anulada_por')->nullable()->constrained('administradores');
            $table->timestamp('anulada_at')->nullable();
            $table->string('motivo_anulacion', 300)->nullable();
            $table->timestamps();

            // La misma factura del mismo proveedor no se registra dos veces
            $table->unique(['proveedor_id', 'tipo_documento', 'numero_documento'], 'compras_documento_unico');
            $table->index(['estado', 'fecha']);
        });

        Schema::create('compras_detalle', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compra_id')->constrained('compras')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos');
            $table->foreignId('orden_detalle_id')->nullable()->constrained('ordenes_compra_detalle');
            $table->foreignId('unidad_medida_id')->constrained('unidades_medida');
            $table->decimal('cantidad', 12, 4);          // en la unidad de compra
            $table->decimal('costo_unitario', 12, 4);    // por unidad de compra
            $table->decimal('subtotal', 12, 2);
            $table->decimal('cantidad_base', 12, 4);     // convertida a la unidad del producto
            $table->decimal('costo_base', 12, 4);        // costo por unidad del producto
            $table->timestamps();

            $table->index(['producto_id', 'created_at']);
        });

        Schema::create('cuentas_pagar', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proveedor_id')->constrained('proveedores');
            $table->foreignId('compra_id')->unique()->constrained('compras');
            $table->decimal('monto', 12, 2);
            $table->decimal('saldo', 12, 2);
            $table->date('fecha_vencimiento');
            $table->enum('estado', ['pendiente', 'pagada', 'anulada'])->default('pendiente');
            $table->timestamps();

            $table->index(['proveedor_id', 'estado']);
            $table->index(['estado', 'fecha_vencimiento']);
        });

        Schema::create('pagos_proveedor', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('numero', 20)->unique(); // PP-000001
            $table->foreignId('proveedor_id')->constrained('proveedores');
            $table->date('fecha');
            $table->decimal('monto', 12, 2);
            // caja: sale de la gaveta del turno abierto; externo: banco u otra cuenta del negocio
            $table->enum('origen', ['caja', 'externo']);
            $table->foreignId('turno_id')->nullable()->constrained('turnos_caja');
            $table->foreignId('metodo_pago_id')->constrained('metodos_pago');
            $table->string('referencia', 100)->nullable();
            $table->foreignId('usuario_id')->constrained('administradores');
            $table->boolean('anulado')->default(false);
            $table->foreignId('anulado_por')->nullable()->constrained('administradores');
            $table->timestamp('anulado_at')->nullable();
            $table->string('motivo_anulacion', 300)->nullable();
            $table->timestamps();
        });

        Schema::create('pagos_proveedor_detalle', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pago_id')->constrained('pagos_proveedor')->cascadeOnDelete();
            $table->foreignId('cuenta_pagar_id')->constrained('cuentas_pagar');
            $table->decimal('monto', 12, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pagos_proveedor_detalle');
        Schema::dropIfExists('pagos_proveedor');
        Schema::dropIfExists('cuentas_pagar');
        Schema::dropIfExists('compras_detalle');
        Schema::dropIfExists('compras');
        Schema::dropIfExists('ordenes_compra_detalle');
        Schema::dropIfExists('ordenes_compra');
    }
};
