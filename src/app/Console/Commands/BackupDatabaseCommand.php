<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class BackupDatabaseCommand extends Command
{
    protected $signature = 'adbana:backup {--restaurante_id= : ID opcional del restaurante a respaldar} {--keep=10 : Cantidad máxima de respaldos a conservar}';
    protected $description = 'Genera un respaldo completo y portable (JSON + resumen SHA-256) de la base de datos en storage/app/backups con rotación automática.';

    public function handle(): int
    {
        $disco = Storage::disk('local');
        if (!$disco->exists('backups')) {
            $disco->makeDirectory('backups');
        }

        $restauranteId = $this->option('restaurante_id');
        $tablas = [
            'restaurantes',
            'users',
            'categorias',
            'productos',
            'pedidos',
            'detalle_pedidos',
            'movimientos_stock',
            'cajas',
            'gastos',
            'auditoria',
        ];

        $contenido = [
            'meta' => [
                'proyecto' => 'Adbana SaaS',
                'generado_en' => now()->toIso8601String(),
                'entorno' => config('app.env'),
                'restaurante_id' => $restauranteId ? (int) $restauranteId : 'todos',
            ],
            'tablas' => [],
            'conteos' => [],
        ];

        foreach ($tablas as $tabla) {
            $query = DB::table($tabla);
            if ($restauranteId !== null && $restauranteId !== '') {
                if ($tabla === 'restaurantes') {
                    $query->where('id', (int) $restauranteId);
                } elseif ($tabla === 'detalle_pedidos') {
                    $query->whereIn('pedido_id', function ($sub) use ($restauranteId) {
                        $sub->select('id')->from('pedidos')->where('restaurante_id', (int) $restauranteId);
                    });
                } else {
                    $query->where('restaurante_id', (int) $restauranteId);
                }
            }

            $registros = $query->orderBy('id')->get()->map(function ($fila) {
                $arr = (array) $fila;
                unset($arr['password'], $arr['remember_token']);
                return $arr;
            })->all();

            $contenido['tablas'][$tabla] = $registros;
            $contenido['conteos'][$tabla] = count($registros);
        }

        $json = json_encode($contenido, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $sufijo = $restauranteId ? "rest_{$restauranteId}" : 'full';
        $nombreArchivo = sprintf('backup_%s_%s_%s.json', $sufijo, now()->format('Ymd_His'), substr( md5(uniqid('', true)), 0, 6));
        $rutaRelativa = "backups/{$nombreArchivo}";

        $disco->put($rutaRelativa, $json);

        // Rotación automática de respaldos antiguos
        $maxKeep = max(1, (int) $this->option('keep'));
        $archivos = collect($disco->files('backups'))
            ->filter(fn (string $f) => str_ends_with($f, '.json') || str_ends_with($f, '.sql'))
            ->sortByDesc(fn (string $f) => $disco->lastModified($f))
            ->values();

        if ($archivos->count() > $maxKeep) {
            foreach ($archivos->slice($maxKeep) as $antiguo) {
                $disco->delete($antiguo);
            }
        }

        $hash = hash('sha256', (string) $json);
        $this->info("Respaldo generado: {$nombreArchivo} (SHA256: {$hash})");

        return self::SUCCESS;
    }
}
