<?php

namespace App\Models\Concerns;

use App\Models\Restaurante;
use Illuminate\Support\Facades\Cache;

trait LimpiaCacheCarta
{
    protected static function bootLimpiaCacheCarta(): void
    {
        $limpiar = function ($model) {
            $slug = Restaurante::whereKey($model->restaurante_id)->value('slug');

            if ($slug) {
                Cache::forget("carta:$slug");
            }
        };

        static::saved($limpiar);
        static::deleted($limpiar);
    }
}