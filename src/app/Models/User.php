<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, Auditable;

    protected array $auditoriaExcluir = ['password', 'remember_token', 'email_verified_at'];

    protected $fillable = [
        'restaurante_id',
        'name',
        'email',
        'password',
        'rol',
        'activo',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'activo' => 'boolean',
        ];
    }

    public function restaurante()
    {
        return $this->belongsTo(Restaurante::class);
    }

    public function getPermisosAttribute(): array
    {
        $activo = $this->activo && $this->restaurante?->activo;
        return [
            'pedidos' => $activo && in_array($this->rol, ['dueno', 'cajero', 'mozo', 'mozo_cajero'], true),
            'cocina' => $activo && in_array($this->rol, ['dueno', 'cajero', 'cocina'], true),
            'caja' => $activo && in_array($this->rol, ['dueno', 'cajero', 'mozo_cajero'], true),
            'administracion' => $activo && $this->rol === 'dueno',
        ];
    }

    public function esSuperadmin(): bool
    {
        return $this->rol === 'superadmin';
    }

    public function esDueno(): bool
    {
        return $this->rol === 'dueno';
    }

    public function esCajero(): bool
    {
        return $this->rol === 'cajero';
    }
}