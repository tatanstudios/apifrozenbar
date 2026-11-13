<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Cada producto tiene sus propios modificadores: una tarjeta que llevaban varios productos se
     * copia (con sus bloques y opciones) para cada uno, así quitar una opción en uno no toca a otro.
     * Las tarjetas sin producto ya no se usan y se apagan.
     */
    public function up(): void
    {
        $ahora = now();
        $porTarjeta = DB::table('conjunto_producto')->orderBy('producto_id')->get()->groupBy('conjunto_id');

        foreach ($porTarjeta as $conjuntoId => $filas) {
            $tarjeta = DB::table('conjuntos_modificadores')->find($conjuntoId);
            $grupos = DB::table('grupos_modificadores')->where('conjunto_id', $conjuntoId)->get();

            // El primer producto se queda con la tarjeta; cada uno de los demás recibe su copia
            foreach ($filas->slice(1) as $fila) {
                $nombre = DB::table('productos')->where('id', $fila->producto_id)->value('nombre') ?? $tarjeta->nombre;
                $nueva = DB::table('conjuntos_modificadores')->insertGetId([
                    'nombre' => mb_substr($nombre, 0, 80), 'activo' => $tarjeta->activo, 'created_at' => $ahora, 'updated_at' => $ahora,
                ]);
                foreach ($grupos as $g) {
                    $copia = (array) $g;
                    unset($copia['id']);
                    $grupoNuevo = DB::table('grupos_modificadores')->insertGetId([...$copia, 'conjunto_id' => $nueva, 'created_at' => $ahora, 'updated_at' => $ahora]);
                    foreach (DB::table('modificadores')->where('grupo_id', $g->id)->get() as $opcion) {
                        $o = (array) $opcion;
                        unset($o['id']);
                        DB::table('modificadores')->insert([...$o, 'grupo_id' => $grupoNuevo, 'created_at' => $ahora, 'updated_at' => $ahora]);
                    }
                    DB::table('producto_grupo_modificador')->where('producto_id', $fila->producto_id)->where('grupo_id', $g->id)
                        ->update(['grupo_id' => $grupoNuevo]);
                }
                DB::table('conjunto_producto')->where('producto_id', $fila->producto_id)->update(['conjunto_id' => $nueva]);
            }

            // La tarjeta que se queda lleva el nombre de su producto
            $primero = DB::table('productos')->where('id', $filas->first()->producto_id)->value('nombre');
            if ($primero) {
                DB::table('conjuntos_modificadores')->where('id', $conjuntoId)->update(['nombre' => mb_substr($primero, 0, 80)]);
            }
        }

        $conProducto = DB::table('conjunto_producto')->pluck('conjunto_id');
        $sueltas = DB::table('conjuntos_modificadores')->whereNotIn('id', $conProducto)->pluck('id');
        DB::table('grupos_modificadores')->whereIn('conjunto_id', $sueltas)->update(['activo' => false]);
        DB::table('conjuntos_modificadores')->whereIn('id', $sueltas)->update(['activo' => false]);
    }

    public function down(): void
    {
        // Las copias se quedan: cada producto ya tiene las suyas
    }
};
