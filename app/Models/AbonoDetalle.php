<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AbonoDetalle extends Model
{
    protected $table = 'abonos_detalle';

    protected $fillable = [
        'abono_id',
        'cuenta_cobrar_id',
        'monto',
    ];

    protected $casts = [
        'monto' => 'decimal:2',
    ];

    public function abono()
    {
        return $this->belongsTo(Abono::class);
    }

    public function cuenta()
    {
        return $this->belongsTo(CuentaCobrar::class, 'cuenta_cobrar_id');
    }
}
