<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Comanda extends Model
{
    protected $table = 'comandas';

    protected $fillable = [
        'numero',
        'orden_id',
        'area_id',
        'estado',
        'notas',
        'usuario_id',
        'enviada_at',
        'iniciada_at',
        'terminada_at',
        'entregada_at',
    ];

    protected $casts = [
        'enviada_at' => 'datetime',
        'iniciada_at' => 'datetime',
        'terminada_at' => 'datetime',
        'entregada_at' => 'datetime',
    ];

    public function orden()
    {
        return $this->belongsTo(Orden::class);
    }

    public function area()
    {
        return $this->belongsTo(AreaPreparacion::class, 'area_id');
    }

    public function items()
    {
        return $this->hasMany(OrdenItem::class);
    }

    public function usuario()
    {
        return $this->belongsTo(Administrador::class, 'usuario_id');
    }
}
