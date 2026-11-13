<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Cliente extends Model
{
    use SoftDeletes;

    protected $table = 'clientes';

    protected $fillable = [
        'nombre',
        'documento',
        'telefono',
        'correo',
        'direccion',
        'limite_credito',
        'dias_credito',
        'saldo',
        'notas',
        'activo',
    ];

    protected $casts = [
        'limite_credito' => 'decimal:2',
        'dias_credito'   => 'integer',
        'saldo'          => 'decimal:2',
        'activo'         => 'boolean',
    ];

    public function cuentas()
    {
        return $this->hasMany(CuentaCobrar::class);
    }

    public function abonos()
    {
        return $this->hasMany(Abono::class);
    }

    public function ventas()
    {
        return $this->hasMany(Venta::class);
    }

    // Crédito que aún puede usar
    public function creditoDisponible(): float
    {
        return round(max(0, (float) $this->limite_credito - (float) $this->saldo), 2);
    }
}
