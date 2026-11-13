<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Proveedor extends Model
{
    use SoftDeletes;

    protected $table = 'proveedores';

    protected $fillable = [
        'nombre',
        'nit',
        'nrc',
        'contacto',
        'telefono',
        'correo',
        'direccion',
        'dias_credito',
        'saldo',
        'notas',
        'activo',
    ];

    protected $casts = [
        'dias_credito' => 'integer',
        'saldo' => 'decimal:2',
        'activo' => 'boolean',
    ];

    public function compras()
    {
        return $this->hasMany(Compra::class);
    }

    public function cuentas()
    {
        return $this->hasMany(CuentaPagar::class);
    }

    public function pagos()
    {
        return $this->hasMany(PagoProveedor::class);
    }
}
