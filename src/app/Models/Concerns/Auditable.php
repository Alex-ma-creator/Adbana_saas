<?php

namespace App\Models\Concerns;

use App\Models\Auditoria;
use Illuminate\Support\Facades\Auth;

trait Auditable
{
    protected static function bootAuditable(): void
    {
        static::created(function ($modelo) {
            $modelo->registrarAuditoria('crear', null, $modelo->datosAuditables($modelo->getAttributes()));
        });

        static::updated(function ($modelo) {
            $nuevos = $modelo->datosAuditables($modelo->getChanges());

            if (empty($nuevos)) {
                return;
            }

            $anteriores = [];

            foreach (array_keys($nuevos) as $campo) {
                $anteriores[$campo] = $modelo->getRawOriginal($campo);
            }

            $modelo->registrarAuditoria('actualizar', $anteriores, $nuevos);
        });

        static::deleted(function ($modelo) {
            $modelo->registrarAuditoria('eliminar', $modelo->datosAuditables($modelo->getAttributes()), null);
        });
    }

    protected function datosAuditables(array $datos): array
    {
        $excluir = array_merge(
            ['id', 'created_at', 'updated_at'],
            property_exists($this, 'auditoriaExcluir') ? $this->auditoriaExcluir : []
        );

        return array_diff_key($datos, array_flip($excluir));
    }

    protected function registrarAuditoria(string $accion, ?array $anterior, ?array $nuevo): void
    {
        $user = Auth::user();

        // Sin usuario logueado (por ejemplo, el cliente que pide por la carta) no se audita.
        if (! $user) {
            return;
        }

        Auditoria::create([
            'restaurante_id' => $user->restaurante_id ?? $this->restaurante_id ?? null,
            'user_id'        => $user->id,
            'accion'         => $accion,
            'tabla'          => $this->getTable(),
            'registro_id'    => $this->getKey(),
            'valor_anterior' => $anterior,
            'valor_nuevo'    => $nuevo,
            'created_at'     => now(),
        ]);
    }
}