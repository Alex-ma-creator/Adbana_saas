<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Auth;
use LogicException;

/** Contexto acotado a una petición o tarea; nunca se conserva en un singleton. */
final class RestauranteContexto
{
    private ?int $restauranteId = null;

    public function id(): int
    {
        $id = $this->restauranteId ?? Auth::user()?->restaurante_id;
        if (! $id) {
            throw new LogicException('La operación requiere un contexto de restaurante explícito.');
        }
        return (int) $id;
    }

    public function ejecutar(int $restauranteId, Closure $operacion): mixed
    {
        if ($restauranteId < 1) {
            throw new LogicException('Identificador de restaurante inválido.');
        }
        $anterior = $this->restauranteId;
        $this->restauranteId = $restauranteId;
        try {
            return $operacion();
        } finally {
            $this->restauranteId = $anterior;
        }
    }
}
