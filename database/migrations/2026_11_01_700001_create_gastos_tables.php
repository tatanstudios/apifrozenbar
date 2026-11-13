<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * GASTOS
     */
    public function up(): void
    {
        Schema::create('categorias_gasto', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100)->unique();
            $table->string('descripcion', 300)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('gastos', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('numero', 20)->unique();                 // G-000001
            $table->date('fecha');
            $table->foreignId('categoria_id')->constrained('categorias_gasto');
            $table->foreignId('proveedor_id')->nullable()->constrained('proveedores');
            $table->string('descripcion', 300);
            $table->decimal('monto', 12, 2);
            // caja: sale de la gaveta del turno abierto; externo: banco, tarjeta u otra cuenta
            $table->enum('origen', ['caja', 'externo']);
            $table->foreignId('turno_id')->nullable()->constrained('turnos_caja');
            $table->foreignId('metodo_pago_id')->constrained('metodos_pago');
            $table->string('referencia', 100)->nullable();
            $table->string('numero_documento', 50)->nullable();     // factura o recibo del gasto
            $table->string('comprobante', 255)->nullable();         // archivo adjunto (disco privado)
            $table->foreignId('usuario_id')->constrained('administradores');
            $table->boolean('anulado')->default(false);
            $table->foreignId('anulado_por')->nullable()->constrained('administradores');
            $table->timestamp('anulado_at')->nullable();
            $table->string('motivo_anulacion', 300)->nullable();
            $table->timestamps();

            $table->index(['fecha', 'anulado']);
            $table->index(['categoria_id', 'fecha']);
        });

        // Categorías habituales de un negocio
        $ahora = now();
        DB::table('categorias_gasto')->insert(collect([
            ['Servicios básicos', 'Luz, agua, teléfono e internet'],
            ['Gas', 'Gas para cocina y plancha'],
            ['Alquiler', 'Alquiler del local'],
            ['Planilla', 'Salarios, horas extra y prestaciones'],
            ['Mantenimiento', 'Reparación de licuadoras, equipo y local'],
            ['Limpieza', 'Productos y servicios de limpieza'],
            ['Transporte', 'Combustible, fletes y repartos'],
            ['Papelería', 'Papel de impresora, talonarios, útiles'],
            ['Impuestos y trámites', 'Pagos municipales, permisos, IVA'],
            ['Otros', null],
        ])->map(fn ($c) => ['nombre' => $c[0], 'descripcion' => $c[1], 'activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora])->all());
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gastos');
        Schema::dropIfExists('categorias_gasto');
    }
};
