<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurante;
use Illuminate\Database\Eloquent\Model;

class Auditoria extends Model
{
    use BelongsToRestaurante;

    protected $table = 'auditoria';

    public $timestamps = false;

    protected $fillable = [
        'restaurante_id', 'user_id', 'accion', 'tabla',
        'registro_id', 'valor_anterior', 'valor_nuevo', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'valor_anterior' => 'array',
            'valor_nuevo' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}