<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\Permisos;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use Auditable, HasApiTokens, HasFactory, Notifiable;

    protected $attributes = ['activo' => true];

    protected array $auditoriaExcluir = ['password', 'remember_token', 'email_verified_at'];

    protected $fillable = [
        'restaurante_id',
        'name',
        'email',
        'google_id',
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
            'permisos_personalizados' => 'array',
        ];
    }

    public function restaurante()
    {
        return $this->belongsTo(Restaurante::class);
    }

    public function getPermisosAttribute(): array
    {
        $permisos = Permisos::resolver($this->rol, $this->permisos_personalizados ?? []);
        if (! $this->activo || ! $this->restaurante?->activo) {
            $permisos = array_fill_keys(array_keys($permisos), false);
        }

        return $permisos + ['administracion' => $this->activo && $this->restaurante?->activo && $this->esDueno()];
    }

    public function tienePermiso(string $permiso): bool
    {
        return $this->permisos[$permiso] ?? false;
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
