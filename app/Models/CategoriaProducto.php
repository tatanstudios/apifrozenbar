<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CategoriaProducto extends Model
{
    protected $table = 'categoria_producto';

    public $incrementing = false;
    public $timestamps   = false;

    protected $fillable = [
        'producto_id',
        'categoria_id',
    ];
}
