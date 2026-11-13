<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AreaPreparacion extends Model
{
    protected $table = 'areas_preparacion';

    protected $fillable = [
        'nombre',
        'color',
        'impresora_id',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function impresora()
    {
        return $this->belongsTo(Impresora::class);
    }

    public function categorias()
    {
        return $this->hasMany(Categoria::class, 'area_preparacion_id');
    }
}
