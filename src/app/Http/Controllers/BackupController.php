<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

class BackupController extends Controller
{
    public function index(Request $request)
    {
        $disco = Storage::disk('local');
        if (!$disco->exists('backups')) {
            return response()->json(['backups' => []]);
        }

        $restauranteId = $request->user()->restaurante_id;
        $prefijoRestaurante = "backup_rest_{$restauranteId}_";

        $archivos = collect($disco->files('backups'))
            ->map(fn (string $ruta) => basename($ruta))
            ->filter(fn (string $nombre) => str_starts_with($nombre, $prefijoRestaurante) || str_starts_with($nombre, 'backup_full_'))
            ->map(function (string $nombre) use ($disco) {
                $ruta = "backups/{$nombre}";
                $contenido = $disco->get($ruta);
                return [
                    'archivo' => $nombre,
                    'tamano_bytes' => $disco->size($ruta),
                    'sha256' => hash('sha256', (string) $contenido),
                    'creado_en' => date(DATE_ATOM, $disco->lastModified($ruta)),
                ];
            })
            ->sortByDesc('creado_en')
            ->values();

        return response()->json(['backups' => $archivos]);
    }

    public function store(Request $request)
    {
        $restauranteId = $request->user()->restaurante_id;

        Artisan::call('adbana:backup', [
            '--restaurante_id' => $restauranteId,
            '--keep' => 10,
        ]);

        $disco = Storage::disk('local');
        $prefijoRestaurante = "backup_rest_{$restauranteId}_";

        $ultimo = collect($disco->files('backups'))
            ->map(fn (string $ruta) => basename($ruta))
            ->filter(fn (string $nombre) => str_starts_with($nombre, $prefijoRestaurante))
            ->sortByDesc(fn (string $nombre) => $disco->lastModified("backups/{$nombre}"))
            ->first();

        abort_if(!$ultimo, 500, 'No se pudo generar el archivo de respaldo.');

        $ruta = "backups/{$ultimo}";
        $contenido = (string) $disco->get($ruta);

        return response()->json([
            'message' => 'Respaldo generado correctamente.',
            'backup' => [
                'archivo' => $ultimo,
                'tamano_bytes' => $disco->size($ruta),
                'sha256' => hash('sha256', $contenido),
                'creado_en' => date(DATE_ATOM, $disco->lastModified($ruta)),
            ],
        ], 201);
    }

    public function descargar(Request $request, string $archivo)
    {
        $nombreLimpio = basename($archivo);
        $restauranteId = $request->user()->restaurante_id;
        $prefijoRestaurante = "backup_rest_{$restauranteId}_";

        if (!str_starts_with($nombreLimpio, $prefijoRestaurante) && !str_starts_with($nombreLimpio, 'backup_full_')) {
            abort(403, 'No tienes permiso para acceder a este respaldo.');
        }

        $disco = Storage::disk('local');
        $ruta = "backups/{$nombreLimpio}";

        abort_unless($disco->exists($ruta), 404, 'Respaldo no encontrado.');

        return response($disco->get($ruta), 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $nombreLimpio . '"',
        ]);
    }
}
