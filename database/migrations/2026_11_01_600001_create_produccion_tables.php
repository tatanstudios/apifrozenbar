<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MERMAS
     */
    public function up(): void
    {
        Schema::create('recetas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')->constrained('productos');       // lo que se produce
            $table->string('nombre', 150);
            $table->decimal('rendimiento', 12, 4);                            // cuánto sale, en la unidad del producto
            // produccion: se fabrica y suma existencia; venta: se descuenta al vender (limonada, platos)
            $table->enum('uso', ['produccion', 'venta'])->default('produccion');
            $table->unsignedInteger('version')->default(1);
            $table->boolean('activa')->default(true);
            $table->string('notas', 1000)->nullable();                        // procedimiento, preparación...
            $table->foreignId('usuario_id')->constrained('administradores');
            $table->timestamps();

            // Una sola receta activa por producto
            $table->unsignedBigInteger('producto_activa_id')->nullable()->storedAs('IF(activa, producto_id, NULL)')->unique();
        });

        Schema::create('recetas_ingredientes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receta_id')->constrained('recetas')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos');       // insumo
            $table->foreignId('unidad_medida_id')->constrained('unidades_medida');
            $table->decimal('cantidad', 12, 4);
            $table->decimal('merma_porcentaje', 5, 2)->default(0);            // desperdicio esperado del insumo
            $table->timestamps();
        });

        Schema::create('producciones', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('numero', 20)->unique();                           // PR-000001
            $table->date('fecha');
            $table->foreignId('receta_id')->constrained('recetas');
            $table->foreignId('producto_id')->constrained('productos');
            $table->decimal('cantidad_planificada', 12, 4);                   // lo que debía salir según la receta
            $table->decimal('cantidad_producida', 12, 4);                     // lo que realmente salió
            $table->decimal('costo_total', 12, 4);
            $table->decimal('costo_unitario', 12, 4);
            $table->enum('estado', ['registrada', 'anulada'])->default('registrada');
            $table->string('observaciones', 500)->nullable();
            $table->foreignId('usuario_id')->constrained('administradores');
            $table->foreignId('turno_id')->nullable()->constrained('turnos_caja'); // turno abierto cuando se registró
            $table->foreignId('anulada_por')->nullable()->constrained('administradores');
            $table->timestamp('anulada_at')->nullable();
            $table->string('motivo_anulacion', 300)->nullable();
            $table->timestamps();

            $table->index(['fecha', 'estado']);
            $table->index(['producto_id', 'fecha']);
        });

        // Consumo teórico (según receta) frente al real, en la unidad del insumo
        Schema::create('producciones_consumos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('produccion_id')->constrained('producciones')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos');
            $table->decimal('cantidad_teorica', 12, 4);
            $table->decimal('cantidad_real', 12, 4);
            $table->decimal('costo_unitario', 12, 4);
            $table->decimal('costo_total', 12, 4);
            $table->timestamps();
        });

        Schema::create('mermas', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->date('fecha');
            $table->foreignId('producto_id')->constrained('productos');
            $table->decimal('cantidad', 12, 4);
            $table->enum('motivo', ['vencido', 'quemado', 'danado', 'otro']);
            $table->string('detalle', 300)->nullable();
            $table->decimal('costo_unitario', 12, 4);
            $table->decimal('costo_total', 12, 4);
            $table->boolean('anulada')->default(false);
            $table->foreignId('usuario_id')->constrained('administradores');
            $table->foreignId('turno_id')->nullable()->constrained('turnos_caja'); // turno abierto cuando se registró
            $table->foreignId('anulada_por')->nullable()->constrained('administradores');
            $table->timestamp('anulada_at')->nullable();
            $table->string('motivo_anulacion', 300)->nullable();
            $table->timestamps();

            $table->index(['fecha', 'anulada']);
            $table->index(['producto_id', 'fecha']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mermas');
        Schema::dropIfExists('producciones_consumos');
        Schema::dropIfExists('producciones');
        Schema::dropIfExists('recetas_ingredientes');
        Schema::dropIfExists('recetas');
    }
};
