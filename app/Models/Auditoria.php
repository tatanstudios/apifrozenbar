<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Auditoria extends Model
{
    protected $table = 'auditorias';

    const UPDATED_AT = null;

    protected $fillable = [
        'usuario_id',
        'modulo',
        'accion',
        'entidad_tipo',
        'entidad_id',
        'descripcion',
        'datos_antes',
        'datos_despues',
        'ip',
        'user_agent',
    ];

    protected $casts = [
        'datos_antes'   => 'array',
        'datos_despues' => 'array',
        'created_at'    => 'datetime',
    ];

    public function usuario()
    {
        return $this->belongsTo(Administrador::class, 'usuario_id');
    }
}
