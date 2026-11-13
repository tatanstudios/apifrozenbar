<?php

namespace App\Models;

use App\Models\Concerns\RegistraTurno;
use Illuminate\Database\Eloquent\Model;

class EntradaInventario extends Model
{
    use RegistraTurno;

    protected $table = 'entradas_inventario';

    protected $fillable = [
        'fecha',
        'tipo',
        'descripcion',
        'usuario_id',
        'autorizado_por',
    ];

    protected $casts = [
        'fecha' => 'date',
    ];

    public function detalles()
    {
        return $this->hasMany(EntradaInventarioDetalle::class, 'entrada_id');
    }

    public function usuario()
    {
        return $this->belongsTo(Administrador::class, 'usuario_id');
    }

    public function autorizadoPor()
    {
        return $this->belongsTo(Administrador::class, 'autorizado_por');
    }
}
