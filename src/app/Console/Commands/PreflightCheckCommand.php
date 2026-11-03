<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PreflightCheckCommand extends Command
{
    protected $signature = 'adbana:preflight';
    protected $description = 'Ejecuta pruebas previas de infraestructura (BD, Caché/Redis, Tablas, Permisos y Configuración) para evitar caídas en el servidor.';

    public function handle(): int
    {
        $this->info('=== ADBANA SAAS: PRUEBAS PREVIAS ANTI-CAÍDAS (PRE-FLIGHT) ===');
        $errores = 0;

        // 1. Verificar APP_KEY
        if (empty(config('app.key'))) {
            $this->error('[FALLO] APP_KEY no está configurada.');
            $errores++;
        } else {
            $this->line('[OK] APP_KEY configurada.');
        }

        // 2. Verificar conexión a Base de Datos y tablas esenciales
        try {
            DB::select('SELECT 1');
            $this->line('[OK] Conexión a Base de Datos establecida.');

            $tablasRequeridas = ['restaurantes', 'users', 'categorias', 'productos', 'pedidos', 'detalle_pedidos', 'cajas', 'gastos', 'auditoria'];
            foreach ($tablasRequeridas as $tabla) {
                if (!Schema::hasTable($tabla)) {
                    $this->error("[FALLO] Falta la tabla requerida: {$tabla}");
                    $errores++;
                }
            }
            if ($errores === 0) {
                $this->line('[OK] Esquema de tablas principales verificado.');
            }
        } catch (\Throwable $e) {
            $this->error('[FALLO] No se pudo conectar a la Base de Datos: ' . $e->getMessage());
            $errores++;
        }

        // 3. Verificar Caché / Redis
        try {
            Cache::put('preflight:test', 'activo', 5);
            if (Cache::get('preflight:test') === 'activo') {
                $this->line('[OK] Motor de caché (' . config('cache.default') . ') operativo.');
            } else {
                $this->error('[FALLO] El motor de caché no devolvió el valor esperado.');
                $errores++;
            }
        } catch (\Throwable $e) {
            $this->error('[FALLO] Error en motor de caché: ' . $e->getMessage());
            $errores++;
        }

        // 4. Verificar permisos de escritura en storage
        $directorios = [
            storage_path('logs'),
            storage_path('framework/cache'),
            storage_path('framework/views'),
            storage_path('app'),
        ];

        foreach ($directorios as $dir) {
            if (!is_dir($dir) || !is_writable($dir)) {
                $this->error("[FALLO] Directorio sin permisos de escritura: {$dir}");
                $errores++;
            }
        }
        $this->line('[OK] Permisos de escritura en storage verificados.');

        // 5. Verificar CORS sin comodín inseguro
        $origenes = (array) config('cors.allowed_origins', []);
        if (empty($origenes) || in_array('*', $origenes, true)) {
            $this->error('[FALLO] Configuración CORS insegura o vacía.');
            $errores++;
        } else {
            $this->line('[OK] Política CORS estricta activa (' . implode(', ', $origenes) . ').');
        }

        if ($errores > 0) {
            $this->error("Resultado: {$errores} verificación(es) fallaron. Revisa antes de abrir tráfico.");
            return self::FAILURE;
        }

        $this->info('Resultado: Todos los servicios están listos y verificados (0 errores).');
        return self::SUCCESS;
    }
}
