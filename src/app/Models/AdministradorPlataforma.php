<?php

namespace App\Models;

use App\Support\AdministracionPlataforma;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;

class AdministradorPlataforma extends Authenticatable
{
    use SoftDeletes;

    protected $table = 'administradores_plataforma';

    protected $fillable = ['name', 'email', 'password', 'rol', 'activo', 'permisos'];

    protected $hidden = ['password', 'pin_confirmacion', 'remember_token', 'sesion_version'];

    protected $appends = ['confirmacion_con_pin'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'pin_confirmacion' => 'hashed', 'activo' => 'boolean', 'permisos' => 'array', 'ultimo_acceso_at' => 'datetime', 'sesion_version' => 'integer'];
    }

    public function getConfirmacionConPinAttribute(): bool
    {
        return $this->pin_confirmacion !== null;
    }

    public function tienePermiso(string $permiso): bool
    {
        if ($permiso === 'administradores') {
            return $this->activo && ! $this->trashed() && $this->rol === 'superadmin';
        }

        return $this->activo && ! $this->trashed() && ($this->rol === 'superadmin' || ($this->permisos[$permiso] ?? false));
    }

    public function getPermisosEfectivosAttribute(): array
    {
        $result = [];
        foreach (AdministracionPlataforma::PERMISOS as $key => $title) {
            $result[$key] = $this->tienePermiso($key);
        }

        return $result + ['administradores' => $this->activo && ! $this->trashed() && $this->rol === 'superadmin'];
    }
}
