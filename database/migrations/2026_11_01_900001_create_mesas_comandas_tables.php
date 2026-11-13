<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Dónde se prepara cada cosa (cocina, barra); la categoría del producto lo decide
        Schema::create('areas_preparacion', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 60)->unique();
            $table->string('color', 7)->default('#0891b2');
            // Cada área sale en su propio ticket; sin impresora va a la de comandas o de tickets
            $table->foreignId('impresora_id')->nullable()->constrained('impresoras')->nullOnDelete();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::table('categorias', function (Blueprint $table) {
            // Sin área: se entrega directo (postre de vitrina) y no genera comanda
            $table->foreignId('area_preparacion_id')->nullable()->after('nombre')->constrained('areas_preparacion')->nullOnDelete();
        });

        // Terminales del mapa: mesas y la caja de mostrador (para llevar se cobra en venta rápida)
        Schema::create('terminales', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 60);
            $table->enum('tipo', ['mesa', 'caja'])->default('mesa');
            $table->string('zona', 60)->nullable();          // Salón, Terraza, Barra...
            $table->unsignedSmallInteger('capacidad')->default(4);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->foreignId('caja_id')->nullable()->constrained('cajas')->nullOnDelete(); // tipo caja: su caja
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        // Modificadores: grupos (Leche, Saborizante, Tamaño) con opciones que pueden sumar al precio
        Schema::create('grupos_modificadores', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 80);
            $table->boolean('obligatorio')->default(false); // hay que elegir al menos una opción
            $table->boolean('multiple')->default(false);    // se pueden elegir varias
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('modificadores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grupo_id')->constrained('grupos_modificadores')->cascadeOnDelete();
            $table->string('nombre', 80);
            $table->decimal('precio', 12, 2)->default(0);
            // Insumo que gasta la opción al venderse (fresa, miel); vacío si no gasta nada (término del huevo)
            $table->foreignId('producto_id')->nullable()->constrained('productos')->nullOnDelete();
            $table->decimal('cantidad', 12, 4)->nullable();
            $table->foreignId('unidad_medida_id')->nullable()->constrained('unidades_medida')->nullOnDelete();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('producto_grupo_modificador', function (Blueprint $table) {
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $table->foreignId('grupo_id')->constrained('grupos_modificadores')->cascadeOnDelete();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->primary(['producto_id', 'grupo_id']);
        });

        // Orden abierta de una terminal (la "cuenta de la mesa"). Una abierta por terminal.
        Schema::create('ordenes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('numero', 20)->unique();                       // O-000001
            $table->foreignId('terminal_id')->constrained('terminales');
            $table->enum('estado', ['abierta', 'cerrada', 'cancelada'])->default('abierta');
            $table->unsignedSmallInteger('comensales')->nullable();
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->foreignId('usuario_id')->constrained('administradores');  // quien la abrió
            $table->foreignId('turno_id')->nullable()->constrained('turnos_caja'); // turno abierto cuando se registró
            $table->string('notas', 300)->nullable();
            $table->timestamp('abierta_at');
            $table->timestamp('cerrada_at')->nullable();
            $table->foreignId('cancelada_por')->nullable()->constrained('administradores');
            $table->string('motivo_cancelacion', 300)->nullable();
            $table->timestamps();

            $table->unsignedBigInteger('terminal_abierta_id')->nullable()->storedAs("IF(estado = 'abierta', terminal_id, NULL)")->unique();
            $table->index(['estado', 'abierta_at']);
        });

        // Cuentas separadas dentro de la orden
        Schema::create('ordenes_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orden_id')->constrained('ordenes')->cascadeOnDelete();
            $table->string('nombre', 60);                                  // Ticket 1, Luz, Sra. Ana
            $table->enum('estado', ['abierto', 'pagado', 'cancelado'])->default('abierto');
            $table->unsignedSmallInteger('posicion')->default(1);
            $table->timestamps();
        });

        // Envíos a cocina o barra
        Schema::create('comandas', function (Blueprint $table) {
            $table->id();
            $table->string('numero', 20);                                  // 000123 (varias áreas comparten número)
            $table->foreignId('orden_id')->constrained('ordenes')->cascadeOnDelete();
            $table->foreignId('area_id')->nullable()->constrained('areas_preparacion')->nullOnDelete();
            $table->enum('estado', ['pendiente', 'en_preparacion', 'terminada', 'entregada', 'cancelada'])->default('pendiente');
            $table->string('notas', 300)->nullable();
            $table->foreignId('usuario_id')->constrained('administradores');
            $table->timestamp('enviada_at');
            $table->timestamp('iniciada_at')->nullable();
            $table->timestamp('terminada_at')->nullable();
            $table->timestamp('entregada_at')->nullable();
            $table->timestamps();

            $table->index(['estado', 'area_id']);
        });

        Schema::create('ordenes_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orden_id')->constrained('ordenes')->cascadeOnDelete();
            $table->foreignId('ticket_id')->constrained('ordenes_tickets');
            $table->foreignId('producto_id')->constrained('productos');
            $table->string('nombre_producto', 200);
            $table->decimal('cantidad', 12, 4);
            $table->decimal('precio_base', 12, 2);
            $table->json('modificadores')->nullable();                     // [{grupo, opcion, precio}]
            // Cambios a la receta sin cambiar el precio: [{producto_id, nombre, base, cantidad, unidad}] por unidad
            $table->json('ajustes')->nullable();
            $table->decimal('precio_unitario', 12, 2);                     // base + modificadores
            $table->string('notas', 200)->nullable();
            // pendiente: aún no se envía; enviado → en_preparacion → listo → entregado (lo marca cocina o el mesero)
            $table->enum('estado', ['pendiente', 'enviado', 'en_preparacion', 'listo', 'entregado', 'cancelado'])->default('pendiente');
            $table->foreignId('area_id')->nullable()->constrained('areas_preparacion')->nullOnDelete();
            $table->foreignId('comanda_id')->nullable()->constrained('comandas')->nullOnDelete();
            $table->foreignId('venta_id')->nullable()->constrained('ventas')->nullOnDelete();
            $table->foreignId('usuario_id')->constrained('administradores');
            $table->foreignId('cancelado_por')->nullable()->constrained('administradores');
            $table->string('motivo_cancelacion', 300)->nullable();
            $table->timestamps();

            $table->index(['orden_id', 'estado']);
        });

        Schema::table('ventas', function (Blueprint $table) {
            $table->foreignId('orden_id')->nullable()->after('pedido_id')->constrained('ordenes')->nullOnDelete();
        });

        // Lo básico para empezar: áreas, la caja de mostrador y seis mesas
        $ahora = now();
        DB::table('areas_preparacion')->insert([
            ['nombre' => 'Cocina', 'color' => '#db2777', 'activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora],
            ['nombre' => 'Barra', 'color' => '#0891b2', 'activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora],
        ]);
        $caja = DB::table('cajas')->orderBy('id')->value('id');
        $terminales = [['CAJA 1', 'caja', 'Mostrador', 0, 1]];
        foreach (range(1, 6) as $n) {
            $terminales[] = ["MESA {$n}", 'mesa', 'Salón', 4, 10 + $n];
        }
        DB::table('terminales')->insert(array_map(fn ($t) => [
            'nombre' => $t[0], 'tipo' => $t[1], 'zona' => $t[2], 'capacidad' => $t[3], 'orden' => $t[4],
            'caja_id' => $t[1] === 'caja' ? $caja : null, 'activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora,
        ], $terminales));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('orden_id');
        });
        Schema::dropIfExists('ordenes_items');
        Schema::dropIfExists('comandas');
        Schema::dropIfExists('ordenes_tickets');
        Schema::dropIfExists('ordenes');
        Schema::dropIfExists('producto_grupo_modificador');
        Schema::dropIfExists('modificadores');
        Schema::dropIfExists('grupos_modificadores');
        Schema::dropIfExists('terminales');
        Schema::table('categorias', function (Blueprint $table) {
            $table->dropConstrainedForeignId('area_preparacion_id');
        });
        Schema::dropIfExists('areas_preparacion');
    }
};
