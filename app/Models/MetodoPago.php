<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MetodoPago extends Model
{
    protected $table = 'metodos_pago';

    const TIPOS = ['efectivo', 'tarjeta', 'transferencia', 'credito', 'otro'];

    protected $fillable = [
        'nombre',
        'tipo',
        'afecta_efectivo',
        'requiere_referencia',
        'orden',
        'activo',
    ];

    protected $casts = [
        'afecta_efectivo'     => 'boolean',
        'requiere_referencia' => 'boolean',
        'orden'               => 'integer',
        'activo'              => 'boolean',
    ];

    // El método de efectivo (para ingresos y egresos de gaveta)
    public static function efectivo(): ?self
    {
        return static::where('tipo', 'efectivo')->where('activo', true)->orderBy('orden')->first();
    }
}
