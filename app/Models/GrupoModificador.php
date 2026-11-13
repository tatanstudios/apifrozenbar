<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GrupoModificador extends Model
{
    protected $table = 'grupos_modificadores';

    protected $fillable = [
        'conjunto_id',
        'orden',
        'nombre',
        'obligatorio',
        'multiple',
        'minimo',
        'maximo',
        'activo',
    ];

    protected $casts = [
        'obligatorio' => 'boolean',
        'multiple' => 'boolean',
        'minimo' => 'integer',
        'maximo' => 'integer',
        'activo' => 'boolean',
    ];

    /** Texto para avisos: «al menos 1», «máximo 2», «de 1 a 2». */
    public function reglaTexto(): string
    {
        $min = (int) $this->minimo;
        $max = $this->maximo;
        if ($min > 0 && $max !== null) {
            return $min === $max ? "elige {$min}" : "elige de {$min} a {$max}";
        }
        return $min > 0 ? "elige al menos {$min}" : ($max !== null ? "máximo {$max}" : '');
    }

    public function opciones()
    {
        return $this->hasMany(Modificador::class, 'grupo_id')->orderBy('orden')->orderBy('id');
    }

    public function productos()
    {
        return $this->belongsToMany(Producto::class, 'producto_grupo_modificador', 'grupo_id', 'producto_id');
    }
}
