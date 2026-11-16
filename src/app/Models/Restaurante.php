<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;

class Restaurante extends Model
{
    use SoftDeletes;

    protected static function booted(): void
    {
        $limpiar = static function (self $restaurante): void {
            Cache::forget('carta:'.$restaurante->slug);
            Cache::forget('restaurante_pedido:'.$restaurante->slug);

            $originalSlug = $restaurante->getOriginal('slug');
            if ($originalSlug && $originalSlug !== $restaurante->slug) {
                Cache::forget('carta:'.$originalSlug);
                Cache::forget('restaurante_pedido:'.$originalSlug);
            }
        };

        static::saved($limpiar);
        static::deleted($limpiar);
    }

    protected $attributes = ['activo' => true];

    protected $fillable = ['nombre', 'slug', 'telefono', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'publicado' => 'boolean', 'configuracion' => 'array'];
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function productos()
    {
        return $this->hasMany(Producto::class);
    }
}
