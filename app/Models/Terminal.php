<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Terminal extends Model
{
    protected $table = 'terminales';

    protected $fillable = [
        'nombre',
        'tipo',
        'zona',
        'capacidad',
        'orden',
        'caja_id',
        'activo',
    ];

    protected $casts = [
        'capacidad' => 'integer',
        'orden' => 'integer',
        'activo' => 'boolean',
    ];

    public function caja()
    {
        return $this->belongsTo(Caja::class);
    }

    public function ordenAbierta()
    {
        return $this->hasOne(Orden::class)->where('estado', 'abierta');
    }
}
