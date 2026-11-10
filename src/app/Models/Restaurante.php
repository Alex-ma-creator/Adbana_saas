<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Restaurante extends Model
{
    use SoftDeletes;

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
