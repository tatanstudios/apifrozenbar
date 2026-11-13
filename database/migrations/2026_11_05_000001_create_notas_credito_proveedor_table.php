<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nota de crédito / ajuste de una cuenta por pagar: rebaja lo que se le debe al
     * proveedor sin mover caja (compra digitada de más, devolución, descuento acordado).
     */
    public function up(): void
    {
        Schema::create('notas_credito_proveedor', function (Blueprint $table) {
            $table->id();
            $table->string('numero', 20)->unique(); // NC-000001
            $table->foreignId('proveedor_id')->constrained('proveedores');
            $table->foreignId('cuenta_pagar_id')->constrained('cuentas_pagar');
            $table->date('fecha');
            $table->decimal('monto', 12, 2);
            $table->string('motivo', 300);
            $table->string('documento', 50)->nullable(); // número de la nota que dio el proveedor, si hay
            $table->foreignId('usuario_id')->constrained('administradores');
            $table->boolean('anulada')->default(false);
            $table->foreignId('anulada_por')->nullable()->constrained('administradores');
            $table->timestamp('anulada_at')->nullable();
            $table->string('motivo_anulacion', 300)->nullable();
            $table->timestamps();

            $table->index(['cuenta_pagar_id', 'anulada']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notas_credito_proveedor');
    }
};
