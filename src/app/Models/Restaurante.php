<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Restaurante extends Model
{
    protected $fillable = ['nombre', 'slug', 'telefono', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
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