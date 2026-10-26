<?php

namespace App\Models\Concerns;

use App\Support\RestauranteContexto;
use Illuminate\Database\Eloquent\Builder;

trait BelongsToRestaurante
{
    protected static function bootBelongsToRestaurante(): void
    {
        static::addGlobalScope('restaurante', function (Builder $builder) {
            $builder->where($builder->getModel()->qualifyColumn('restaurante_id'), app(RestauranteContexto::class)->id());
        });

        static::creating(function ($model) {
            // Los procesos públicos y de importación pueden proporcionar un ID validado.
            // Las lecturas posteriores siempre necesitan contexto, incluso para superadmin.
            if (empty($model->restaurante_id)) {
                $model->restaurante_id = app(RestauranteContexto::class)->id();
            }
        });
    }
}
