<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tarjetas de modificadores, como en las apps de delivery: una tarjeta («Bebidas con leche»)
     * tiene varios bloques en orden (Tipo de leche, Azúcar, Extras), cada bloque es un grupo con sus
     * opciones, mínimo y máximo. A la tarjeta se le asignan los productos; un producto lleva una sola.
     *
     * producto_grupo_modificador se conserva como lo que usa la venta: al guardar la tarjeta se
     * copian sus bloques, en orden, a cada producto.
     */
    public function up(): void
    {
        Schema::create('conjuntos_modificadores', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 80);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::table('grupos_modificadores', function (Blueprint $table) {
            $table->foreignId('conjunto_id')->nullable()->after('id')->constrained('conjuntos_modificadores')->nullOnDelete();
            $table->unsignedSmallInteger('orden')->default(0)->after('conjunto_id');
        });

        // Un producto, una tarjeta (producto_id es la llave)
        Schema::create('conjunto_producto', function (Blueprint $table) {
            $table->foreignId('producto_id')->primary()->constrained('productos')->cascadeOnDelete();
            $table->foreignId('conjunto_id')->constrained('conjuntos_modificadores')->cascadeOnDelete();
        });

        $this->convertirLoQueHabia();
    }

    /**
     * Los productos que llevaban los mismos grupos quedan en una misma tarjeta con esos bloques.
     * Si un grupo estaba en productos con combinaciones distintas, cada tarjeta recibe su copia.
     */
    private function convertirLoQueHabia(): void
    {
        $ahora = now();
        $grupos = DB::table('grupos_modificadores')->get()->keyBy('id');
        $porProducto = DB::table('producto_grupo_modificador')->orderBy('orden')->get()
            ->groupBy('producto_id')->map(fn ($filas) => $filas->pluck('grupo_id')->all());

        $combinaciones = [];
        foreach ($porProducto as $productoId => $ids) {
            $combinaciones[implode(',', $ids)]['grupos'] = $ids;
            $combinaciones[implode(',', $ids)]['productos'][] = $productoId;
        }

        $usados = [];
        foreach ($combinaciones as $c) {
            $nombre = mb_substr(collect($c['grupos'])->map(fn ($id) => $grupos[$id]->nombre)->implode(' + '), 0, 80);
            $conjunto = DB::table('conjuntos_modificadores')->insertGetId(['nombre' => $nombre, 'activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora]);

            foreach ($c['grupos'] as $orden => $grupoId) {
                $id = $grupoId;
                if (isset($usados[$grupoId])) {
                    // Ya está en otra tarjeta: esta lleva su propia copia, con sus opciones
                    $copia = (array) $grupos[$grupoId];
                    unset($copia['id']);
                    $id = DB::table('grupos_modificadores')->insertGetId([...$copia, 'created_at' => $ahora, 'updated_at' => $ahora]);
                    foreach (DB::table('modificadores')->where('grupo_id', $grupoId)->get() as $opcion) {
                        $fila = (array) $opcion;
                        unset($fila['id']);
                        DB::table('modificadores')->insert([...$fila, 'grupo_id' => $id, 'created_at' => $ahora, 'updated_at' => $ahora]);
                    }
                    DB::table('producto_grupo_modificador')->whereIn('producto_id', $c['productos'])->where('grupo_id', $grupoId)
                        ->update(['grupo_id' => $id]);
                }
                $usados[$grupoId] = true;
                DB::table('grupos_modificadores')->where('id', $id)->update(['conjunto_id' => $conjunto, 'orden' => $orden]);
            }
            foreach ($c['productos'] as $productoId) {
                DB::table('conjunto_producto')->insert(['producto_id' => $productoId, 'conjunto_id' => $conjunto]);
            }
        }

        // Grupos que no estaban en ningún producto: cada uno en su propia tarjeta
        foreach ($grupos as $g) {
            if (isset($usados[$g->id])) {
                continue;
            }
            $conjunto = DB::table('conjuntos_modificadores')->insertGetId(['nombre' => $g->nombre, 'activo' => (bool) $g->activo, 'created_at' => $ahora, 'updated_at' => $ahora]);
            DB::table('grupos_modificadores')->where('id', $g->id)->update(['conjunto_id' => $conjunto, 'orden' => 0]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('conjunto_producto');
        Schema::table('grupos_modificadores', function (Blueprint $table) {
            $table->dropConstrainedForeignId('conjunto_id');
            $table->dropColumn('orden');
        });
        Schema::dropIfExists('conjuntos_modificadores');
    }
};
