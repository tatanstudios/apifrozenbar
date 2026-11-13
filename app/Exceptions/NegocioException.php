<?php

namespace App\Exceptions;

/**
 * Regla de negocio no cumplida (stock insuficiente, turno cerrado, etc.).
 * Se responde como 422 con el mensaje tal cual, porque está pensado para el usuario.
 */
class NegocioException extends \RuntimeException
{
}
