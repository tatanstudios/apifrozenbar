<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * REGISTRO DE IMPRESORAS
     */
    public function up(): void
    {
        Schema::create('impresoras', function (Blueprint $table) {
            $table->id();
            $table->string('alias', 200);                       // Nombre amigable ej: "Impresora Cocina"
            $table->enum('conexion', ['red', 'windows', 'dispositivo'])->default('red'); // dispositivo: la tablet del local la recibe de la cola y la manda por Wi-Fi (servidor en la nube)
            $table->string('ip_impresora', 50)->nullable();     // IP de la impresora térmica
            $table->unsignedSmallInteger('puerto')->default(9100);
            $table->string('nombre_windows', 200)->nullable();
            $table->string('ip_servidor', 50)->nullable();      // IP del servidor principal
            $table->enum('tipo_impresion', ['escpos', 'star_raster'])->default('escpos'); // star_raster: Star TSP100/TSP143 (solo imprimen imágenes)
            // 48 caracteres = papel de 80 mm · 32 = papel de 58 mm
            $table->unsignedTinyInteger('ancho_caracteres')->default(48);
            // ticket: comprobante del cliente · comanda: preparación por categoría
            $table->enum('uso', ['ticket', 'comanda', 'ambos'])->default('ticket');
            $table->boolean('predeterminada')->default(false);
            $table->boolean('cortar_papel')->default(true);
            $table->enum('cash_drawer', ['ninguno', 'pin2', 'pin5'])->default('ninguno'); // PIN NO.2, PIN NO.5 o ninguno
            $table->boolean('activo')->default(true);

            $table->timestamps();
            $table->softDeletes();
        });

        // Bitácora de impresiones: permite ver errores y reintentar
        Schema::create('trabajos_impresion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('impresora_id')->nullable()->constrained('impresoras')->nullOnDelete();
            $table->string('tipo', 30);                       // ticket, comanda, cierre, prueba, cajon
            $table->string('documento_tipo', 50)->nullable(); // venta, turno...
            $table->unsignedBigInteger('documento_id')->nullable();
            $table->enum('estado', ['pendiente', 'impreso', 'error'])->default('pendiente');
            $table->unsignedTinyInteger('intentos')->default(0);
            $table->string('error', 500)->nullable();
            $table->longText('contenido');                    // bytes ESC/POS en base64
            $table->foreignId('usuario_id')->nullable()->constrained('administradores')->nullOnDelete();
            $table->timestamp('impreso_at')->nullable();
            $table->timestamps();

            $table->index(['estado', 'created_at']);
            $table->index(['documento_tipo', 'documento_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trabajos_impresion');
        Schema::dropIfExists('impresoras');
    }
};
