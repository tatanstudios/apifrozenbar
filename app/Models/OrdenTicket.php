<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrdenTicket extends Model
{
    protected $table = 'ordenes_tickets';

    protected $fillable = [
        'orden_id',
        'nombre',
        'estado',
        'posicion',
    ];

    protected $casts = [
        'posicion' => 'integer',
    ];

    public function orden()
    {
        return $this->belongsTo(Orden::class);
    }

    public function items()
    {
        return $this->hasMany(OrdenItem::class, 'ticket_id');
    }
}
