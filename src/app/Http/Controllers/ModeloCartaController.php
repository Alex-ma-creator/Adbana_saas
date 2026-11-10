<?php

namespace App\Http\Controllers;

use App\Models\Producto;
use App\Models\Restaurante;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ModeloCartaController extends Controller
{
    public function show(string $slug, int $id): BinaryFileResponse
    {
        $restaurante = Restaurante::where('slug', $slug)->where('activo', true)->firstOrFail();
        $producto = Producto::withoutGlobalScopes()->where('restaurante_id', $restaurante->id)->where('activo', true)->where('es_insumo', false)->findOrFail($id);
        abort_unless(is_string($producto->modelo_3d) && str_starts_with($producto->modelo_3d, '/'), 404);
        $root = realpath(public_path());
        $archivo = realpath(public_path(ltrim($producto->modelo_3d, '/')));
        abort_unless($archivo && str_starts_with($archivo, $root.DIRECTORY_SEPARATOR) && is_file($archivo) && strtolower(pathinfo($archivo, PATHINFO_EXTENSION)) === 'glb', 404);

        return response()->file($archivo, ['Content-Type' => 'model/gltf-binary', 'Cache-Control' => 'public, max-age=300']);
    }
}
