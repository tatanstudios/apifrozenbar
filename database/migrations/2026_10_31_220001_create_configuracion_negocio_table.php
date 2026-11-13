<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DATOS CONFIG INICIAL DEL NEGOCIO
     */
    public function up(): void
    {
        // Una sola fila: datos de el negocio y reglas generales del sistema
        Schema::create('configuracion_negocio', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 200);
            $table->string('razon_social', 200)->nullable();
            // Datos fiscales del encabezado del ticket
            $table->string('nit', 30)->nullable();
            $table->string('nrc', 30)->nullable();
            $table->string('actividad_economica', 200)->nullable();
            $table->string('tipo_establecimiento', 100)->nullable()->default('Sucursal / Agencia');
            $table->string('direccion', 300)->nullable();
            $table->string('telefono', 30)->nullable();
            $table->string('correo', 100)->nullable();
            $table->string('logo', 300)->nullable();
            $table->string('moneda', 3)->default('USD');
            $table->string('simbolo_moneda', 5)->default('$');
            $table->boolean('permitir_stock_negativo')->default(true);
            // Porcentaje máximo de descuento que el cajero puede aplicar sin autorización
            $table->decimal('descuento_max_sin_autorizacion', 5, 2)->default(0);
            $table->boolean('cierre_ciego')->default(false); // el cajero cuenta sin ver cuánto debería haber
            $table->boolean('aviso_cambio')->default(true);  // al cobrar, avisa si el cambio no alcanza en la gaveta
            $table->boolean('ticket_por_comprar')->default(true); // el ticket de cierre de turno imprime la sección «POR COMPRAR»
            $table->string('mensaje_ticket', 500)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('configuracion_negocio');
    }
};
