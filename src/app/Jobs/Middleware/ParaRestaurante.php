<?php

namespace App\Jobs\Middleware;

use App\Support\RestauranteContexto;
use Closure;

/** La tarea debe declarar public int $restauranteId. */
final class ParaRestaurante
{
    public function handle(object $job, Closure $next): mixed
    {
        return app(RestauranteContexto::class)->ejecutar($job->restauranteId, fn () => $next($job));
    }
}
