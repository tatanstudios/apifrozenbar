<?php

namespace App\Exceptions;

/**
 * La operación necesita un permiso que el usuario no tiene (ej. un descuento
 * mayor al máximo o una anulación): la autoriza alguien con ese permiso con su
 * PIN o contraseña. Se responde 403 con status SIN_PERMISO y el permiso;
 * "invalido" indica que se mandó un PIN y no sirvió.
 */
class AutorizacionRequeridaException extends NegocioException
{
    public function __construct(public readonly string $permiso, string $mensaje, public readonly bool $invalido = false)
    {
        parent::__construct($mensaje);
    }
}
