<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

trait BelongsToRestaurante
{
    protected static function bootBelongsToRestaurante(): void
    {
        static::addGlobalScope('restaurante', function (Builder $builder) {
            $user = Auth::user();

            if ($user && $user->rol !== 'superadmin') {
                $builder->where(
                    $builder->getModel()->getTable() . '.restaurante_id',
                    $user->restaurante_id
                );
            }
        });

        static::creating(function ($model) {
            $user = Auth::user();

            if ($user && $user->rol !== 'superadmin' && empty($model->restaurante_id)) {
                $model->restaurante_id = $user->restaurante_id;
            }
        });
    }
}