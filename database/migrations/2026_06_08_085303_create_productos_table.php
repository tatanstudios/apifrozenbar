<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PRODUCTOS
     */
    public function up(): void
    {
        Schema::create('productos', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 100)->unique()->nullable(); // auto-generado si viene vacío
            $table->string('codigo_barras', 50)->nullable()->unique();
            $table->string('nombre', 300);
            $table->text('descripcion')->nullable();
            $table->string('imagen', 300)->nullable(); // path del archivo
            $table->enum('tipo', ['bien', 'servicio', 'bien_servicio']);

            // producto_terminado: bebidas, crepas (se producen y se venden)
            // materia_prima: harina, tapioca, leche (se compran y se consumen en recetas)
            // reventa: bebidas, golosinas (se compran y se venden)
            // empaque: bolsas, cajas
            $table->enum('clase', ['producto_terminado', 'materia_prima', 'reventa', 'empaque'])
                ->default('producto_terminado');

            $table->boolean('se_vende')->default(true);          // aparece en el POS
            $table->boolean('consumo_interno')->default(false);  // cena del personal, cortesías: no cobran, solo descuentan su receta
            $table->boolean('controla_stock')->default(true);    // lleva existencias
            $table->boolean('permite_fraccion')->default(false); // cantidades con decimales (peso)
            $table->boolean('imprime_comanda')->default(true);   // false: se entrega directo (coca en lata), nunca manda comanda aunque su categoría tenga área
            $table->unsignedInteger('orden_pos')->default(0);
            $table->string('color_pos', 7)->nullable();          // #RRGGBB del botón en el POS

            // Costo promedio ponderado: necesita más decimales que el precio
            $table->decimal('costo_unitario', 12, 4)->default(0);
            $table->decimal('precio_venta', 12, 2)->default(0);

            $table->foreignId('unidad_medida_id')->constrained('unidades_medida');
            $table->boolean('activo')->default(true);

            $table->index(['activo', 'se_vende']);
            $table->index('clase');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('productos');
    }
};
