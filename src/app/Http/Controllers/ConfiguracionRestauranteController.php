<?php

namespace App\Http\Controllers;

use App\Models\CuentaMesa;
use App\Models\Mesa;
use App\Models\Producto;
use App\Models\Restaurante;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ConfiguracionRestauranteController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'restaurante' => $request->user()->restaurante,
            'mesas' => Mesa::orderBy('zona')->orderBy('nombre')->get(),
            'avance' => [
                'productos' => Producto::where('activo', true)->where('es_insumo', false)->exists(),
                'equipo' => User::where('restaurante_id', $request->user()->restaurante_id)->where('activo', true)->where('id', '!=', $request->user()->id)->exists(),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'modalidades' => ['required', 'array', 'min:1', 'max:3'],
            'modalidades.*' => ['required', 'distinct', 'in:mesa,recojo,delivery'],
            'descripcion' => ['nullable', 'string', 'max:500'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'horario' => ['nullable', 'string', 'max:200'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'publicado' => ['required', 'boolean'],
            'cantidad_mesas' => ['required', 'integer', 'min:0', 'max:25'],
        ], [
            'cantidad_mesas.max' => 'Tu plan permite hasta 25 mesas. Mejora tu plan para crear más mesas y acceder a más beneficios.',
        ]);
        DB::transaction(function () use ($request, $datos): void {
            $restaurante = Restaurante::whereKey($request->user()->restaurante_id)->lockForUpdate()->firstOrFail();
            if (! in_array('mesa', $datos['modalidades'], true) && CuentaMesa::where('abierta', 1)->exists()) {
                throw ValidationException::withMessages(['modalidades' => 'Cierra las cuentas de mesa antes de desactivar esta modalidad.']);
            }
            if ($datos['publicado'] && (! Producto::where('activo', true)->where('es_insumo', false)->exists() || empty($datos['direccion']) || empty($datos['horario']) || empty($datos['telefono']))) {
                throw ValidationException::withMessages(['publicado' => 'Antes de publicar, añade un producto, dirección, horario y teléfono.']);
            }
            if (in_array('mesa', $datos['modalidades'], true)) {
                for ($numero = 1; $numero <= $datos['cantidad_mesas']; $numero++) {
                    $nombre = 'Mesa '.str_pad((string) $numero, 2, '0', STR_PAD_LEFT);
                    Mesa::firstOrCreate(['nombre_key' => mb_strtolower($nombre)], ['nombre' => $nombre, 'zona' => 'Salón', 'codigo' => (string) Str::uuid()]);
                }
            }
            $restaurante->forceFill([
                'telefono' => $datos['telefono'],
                'publicado' => $datos['publicado'],
                'configuracion' => array_merge($restaurante->configuracion ?? [], collect($datos)->except(['publicado', 'telefono', 'cantidad_mesas'])->all()),
            ])->save();
        });
        Cache::forget('carta:'.$request->user()->restaurante->slug);

        $request->user()->unsetRelation('restaurante');

        return $this->show($request);
    }

    public function guardarLogo(Request $request): JsonResponse
    {
        $request->validate([
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048', 'dimensions:max_width=2048,max_height=2048'],
        ]);
        $restauranteId = $request->user()->restaurante_id;
        $ruta = $request->file('logo')->store('logos-restaurantes/'.$restauranteId, 'public');
        abort_unless(is_string($ruta), 500, 'No se pudo guardar el logo.');
        try {
            $restaurante = DB::transaction(function () use ($restauranteId, $ruta): Restaurante {
                $restaurante = Restaurante::whereKey($restauranteId)->lockForUpdate()->firstOrFail();
                $configuracion = $restaurante->configuracion ?? [];
                $configuracion['logo'] = '/logos-restaurantes/'.$restauranteId.'/'.basename($ruta);
                $restaurante->forceFill(['configuracion' => $configuracion])->save();

                return $restaurante;
            });
        } catch (\Throwable $exception) {
            Storage::disk('public')->delete($ruta);
            throw $exception;
        }

        return response()->json(['restaurante' => $restaurante]);
    }

    public function mostrarLogo(int $restaurante, string $archivo): StreamedResponse
    {
        abort_unless(Restaurante::whereKey($restaurante)->where('activo', true)->exists(), 404);
        $ruta = 'logos-restaurantes/'.$restaurante.'/'.$archivo;
        abort_unless(Storage::disk('public')->exists($ruta), 404);

        return Storage::disk('public')->response($ruta, null, [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    public function mesa(Request $request, int $id): JsonResponse
    {
        $datos = $request->validate(['nombre' => ['required', 'string', 'max:20'], 'zona' => ['required', 'string', 'max:60'], 'activa' => ['required', 'boolean'], 'capacidad' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100']]);
        $mesa = DB::transaction(function () use ($request, $datos, $id): Mesa {
            Restaurante::whereKey($request->user()->restaurante_id)->lockForUpdate()->firstOrFail();
            $mesa = Mesa::lockForUpdate()->findOrFail($id);
            $nombre = trim($datos['nombre']);
            $key = mb_strtolower($nombre);
            if ($nombre === '' || Mesa::where('nombre_key', $key)->where('id', '!=', $id)->exists()) {
                throw ValidationException::withMessages(['nombre' => 'Indica un nombre único para esta mesa.']);
            }
            if (CuentaMesa::where('mesa_key', $mesa->nombre_key)->where('abierta', 1)->exists()) {
                throw ValidationException::withMessages(['mesa' => 'Cierra la cuenta antes de modificar esta mesa.']);
            }
            $mesa->update(array_merge($datos, ['nombre' => $nombre, 'nombre_key' => $key]));

            return $mesa;
        });

        return response()->json($mesa);
    }

    public function eliminarMesa(Request $request, int $id): JsonResponse
    {
        DB::transaction(function () use ($request, $id): void {
            Restaurante::whereKey($request->user()->restaurante_id)->lockForUpdate()->firstOrFail();
            $mesa = Mesa::lockForUpdate()->findOrFail($id);
            if (CuentaMesa::where('mesa_key', $mesa->nombre_key)->where('abierta', 1)->exists()) {
                throw ValidationException::withMessages(['mesa' => 'Cierra la cuenta antes de eliminar esta mesa.']);
            }
            $mesa->delete();
        });

        return response()->json(['eliminada' => true]);
    }
}
