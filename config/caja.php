<?php

/*
|--------------------------------------------------------------------------
| Caja
|--------------------------------------------------------------------------
|
| Denominaciones de dólar estadounidense para contar la gaveta en arqueos y
| cierres. La clave es el valor; el sistema calcula el total del lado del
| servidor con estas mismas cantidades.
|
*/

return [

    'denominaciones' => [
        ['valor' => '100',  'tipo' => 'billete', 'etiqueta' => '$100'],
        ['valor' => '50',   'tipo' => 'billete', 'etiqueta' => '$50'],
        ['valor' => '20',   'tipo' => 'billete', 'etiqueta' => '$20'],
        ['valor' => '10',   'tipo' => 'billete', 'etiqueta' => '$10'],
        ['valor' => '5',    'tipo' => 'billete', 'etiqueta' => '$5'],
        ['valor' => '1',    'tipo' => 'billete', 'etiqueta' => '$1'],
        ['valor' => '0.25', 'tipo' => 'moneda',  'etiqueta' => '25¢'],
        ['valor' => '0.10', 'tipo' => 'moneda',  'etiqueta' => '10¢'],
        ['valor' => '0.05', 'tipo' => 'moneda',  'etiqueta' => '5¢'],
        ['valor' => '0.01', 'tipo' => 'moneda',  'etiqueta' => '1¢'],
    ],

];
