<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Modificador extends Model
{
    protected $table = 'modificadores';

    protected $fillable = [
        'grupo_id',
        'nombre',
        'precio',
        'cantidad_maxima',
        'obligatorio',
        'exclusiva',
        'producto_id',
        'cantidad',
        'unidad_medida_id',
        'orden',
        'activo',
        'eliminada',
    ];

    protected $casts = [
        'precio' => 'decimal:2',
        'cantidad' => 'decimal:4',
        'cantidad_maxima' => 'integer',
        'orden' => 'integer',
        'activo' => 'boolean',
        'obligatorio' => 'boolean',
        'exclusiva' => 'boolean',
        'eliminada' => 'boolean',
    ];

    public function grupo()
    {
        return $this->belongsTo(GrupoModificador::class, 'grupo_id');
    }

    // Insumo que se descuenta al vender la opción
    public function insumo()
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function unidad()
    {
        return $this->belongsTo(UnidadesMedida::class, 'unidad_medida_id');
    }

    /**
     * Ids de los extras elegidos, repitiendo los que se pidieron más de una vez
     * (Huevo extra ×2 → [7, 7]): así se cuentan el precio y el insumo.
     * @param array|null $elegidos [['id', 'opcion', 'precio', 'cantidad'?], ...] como se guardan en la orden
     */
    public static function ids(?array $elegidos): array
    {
        return collect($elegidos ?? [])->flatMap(fn ($m) => !empty($m['id'])
            ? array_fill(0, max(1, (int) ($m['cantidad'] ?? 1)), (int) $m['id']) : [])->values()->all();
    }

    /** Texto de cada extra para ticket, comanda y pantalla: «Huevo extra ×2», «Deslactosada» */
    public static function textos(?array $elegidos): array
    {
        return collect($elegidos ?? [])->map(fn ($m) => ($m['opcion'] ?? '')
            . ((int) ($m['cantidad'] ?? 1) > 1 ? ' ×' . (int) $m['cantidad'] : ''))->filter()->values()->all();
    }
}
