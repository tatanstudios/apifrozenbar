<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Caja extends Model
{
    protected $table = 'cajas';

    protected $fillable = [
        'nombre',
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

    public function turnos()
    {
        return $this->hasMany(TurnoCaja::class);
    }

    public function turnoAbierto()
    {
        return $this->hasOne(TurnoCaja::class)->where('estado', 'abierto');
    }
}
