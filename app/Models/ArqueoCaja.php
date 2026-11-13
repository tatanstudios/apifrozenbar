<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArqueoCaja extends Model
{
    protected $table = 'arqueos_caja';

    protected $fillable = [
        'turno_id',
        'tipo',
        'denominaciones',
        'total_contado',
        'efectivo_esperado',
        'diferencia',
        'observaciones',
        'usuario_id',
    ];

    protected $casts = [
        'denominaciones'    => 'array',
        'total_contado'     => 'decimal:2',
        'efectivo_esperado' => 'decimal:2',
        'diferencia'        => 'decimal:2',
    ];

    public function turno()
    {
        return $this->belongsTo(TurnoCaja::class, 'turno_id');
    }

    public function usuario()
    {
        return $this->belongsTo(Administrador::class, 'usuario_id');
    }
}
