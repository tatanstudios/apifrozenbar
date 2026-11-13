<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Producto extends Model
{
    use SoftDeletes;

    const TIPO_BIEN          = 'bien';
    const TIPO_SERVICIO      = 'servicio';
    const TIPO_BIEN_SERVICIO = 'bien_servicio';

    const CLASES = [
        'producto_terminado' => 'Producto terminado',
        'materia_prima'      => 'Materia prima',
        'reventa'            => 'Reventa',
        'empaque'            => 'Empaque',
    ];

    protected $fillable = [
        'sku',
        'codigo_barras',
        'nombre',
        'descripcion',
        'imagen',
        'tipo',
        'clase',
        'se_vende',
        'consumo_interno',
        'controla_stock',
        'permite_fraccion',
        'permite_sin_existencia',
        'imprime_comanda',
        'orden_pos',
        'color_pos',
        'costo_unitario',
        'precio_venta',
        'unidad_medida_id',
        'activo',
    ];

    protected $casts = [
        'costo_unitario'   => 'decimal:4',
        'precio_venta'     => 'decimal:2',
        'se_vende'         => 'boolean',
        'consumo_interno'  => 'boolean',
        'controla_stock'   => 'boolean',
        'permite_fraccion' => 'boolean',
        'permite_sin_existencia' => 'boolean',
        'imprime_comanda'  => 'boolean',
        'orden_pos'        => 'integer',
        'activo'           => 'boolean',
    ];

    // ─── Relaciones ───────────────────────────────────────────────
    /**
     * Área que prepara el producto (Cocina, Barra) según su categoría. Null si no pasa por
     * preparación: su categoría no tiene área o el producto no imprime comanda (se entrega
     * directo, como una coca en lata). Necesita categorias.area cargado.
     */
    public function areaDeComanda(): ?AreaPreparacion
    {
        if ($this->imprime_comanda === false) {
            return null;
        }

        return $this->categorias->map(fn ($c) => $c->area)->first(fn ($a) => $a && $a->activo);
    }

    public function categorias(): BelongsToMany
    {
        return $this->belongsToMany(
            Categoria::class,
            'categoria_producto',
            'producto_id',
            'categoria_id'
        );
    }

    public function unidadMedida(): BelongsTo
    {
        return $this->belongsTo(UnidadesMedida::class, 'unidad_medida_id');
    }

    // ─── Helpers ──────────────────────────────────────────────────
    public function manejaBien(): bool
    {
        return in_array($this->tipo, [self::TIPO_BIEN, self::TIPO_BIEN_SERVICIO]);
    }

    public function manejaServicio(): bool
    {
        return in_array($this->tipo, [self::TIPO_SERVICIO, self::TIPO_BIEN_SERVICIO]);
    }

    // Recetas (de producción o de venta) en las que este producto es ingrediente
    public function enRecetas()
    {
        return $this->belongsToMany(Receta::class, 'recetas_ingredientes', 'producto_id', 'receta_id');
    }

    public function inventario()
    {
        return $this->hasOne(Inventario::class);
    }

    public function movimientos()
    {
        return $this->hasMany(MovimientoInventario::class);
    }

    public function gruposModificadores()
    {
        return $this->belongsToMany(GrupoModificador::class, 'producto_grupo_modificador', 'producto_id', 'grupo_id')
            ->withPivot('orden')->orderBy('producto_grupo_modificador.orden');
    }
}
