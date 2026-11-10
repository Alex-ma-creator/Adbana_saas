<?php

namespace App\Http\Controllers;

use App\Models\DetallePedido;
use App\Models\MovimientoStock;
use App\Models\Producto;
use App\Models\Receta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Validation\Rule;

class ProductoController extends Controller
{
    public function index(Request $request)
    {
        $productos = Producto::with(['categoria:id,nombre', 'receta.insumo'])->when($request->boolean('venta'), fn ($q) => $q->where('es_insumo', false))->when($request->filled('categoria_id'), fn ($q) => $q->where('categoria_id', $request->integer('categoria_id')))->when($request->filled('q'), fn ($q) => $q->whereLike('nombre', '%'.$request->input('q').'%'))->orderBy('nombre')->paginate(20);
        if (! $request->user()->tienePermiso('productos')) {
            $productos->getCollection()->each->makeHidden(['costo', 'receta']);
        }

        return $productos;
    }

    public function show(Request $request, string $id)
    {
        $producto = Producto::with(['categoria:id,nombre', 'receta.insumo'])->findOrFail($id);
        if (! $request->user()->tienePermiso('productos')) {
            $producto->makeHidden(['costo', 'receta']);
        }

        return $producto;
    }

    public function store(Request $request)
    {
        $datos = $this->validar($request);
        $producto = DB::transaction(function () use ($request, $datos) {
            $producto = Producto::create($datos);
            if (($datos['stock'] ?? 0) > 0) {
                MovimientoStock::create(['producto_id' => $producto->id, 'user_id' => $request->user()->id, 'tipo' => 'entrada', 'cantidad' => $datos['stock'], 'motivo' => 'stock_inicial']);
            }

            return $producto;
        });

        Cache::forget('carta:'.$request->user()->restaurante->slug);

        return response()->json($producto->load('categoria:id,nombre'), 201);
    }

    public function update(Request $request, string $id)
    {
        $producto = Producto::findOrFail($id);
        DB::transaction(function () use ($request, $id) {
            $producto = Producto::lockForUpdate()->findOrFail($id);
            $datos = $this->validar($request, true);
            if (($datos['es_insumo'] ?? false) && $producto->receta()->exists()) {
                abort(422, 'Un producto con receta no puede convertirse en insumo.');
            }
            $producto->update($datos);
        });
        $producto->refresh();
        Cache::forget('carta:'.$request->user()->restaurante->slug);

        return response()->json($producto->load('categoria:id,nombre'));
    }

    public function destroy(string $id)
    {
        $producto = Producto::findOrFail($id);
        if (DB::table('consumos_pedido')->where('producto_id', $producto->id)->exists() || Receta::where('insumo_id', $producto->id)->exists() || $producto->receta()->exists() || DetallePedido::where('producto_id', $producto->id)->exists()) {
            return response()->json(['message' => 'El producto tiene pedidos. Desactívalo en vez de eliminarlo.'], 409);
        }
        $producto->delete();

        return response()->json(['message' => 'Producto eliminado.']);
    }

    public function foto(string $archivo): StreamedResponse
    {
        $ruta = 'fotos-platos/'.$archivo;
        abort_unless(Storage::disk('public')->exists($ruta), 404);

        return Storage::disk('public')->response($ruta, null, ['X-Content-Type-Options' => 'nosniff']);
    }

    private function validar(Request $request, bool $parcial = false): array
    {
        $obligatorio = $parcial ? 'sometimes' : 'required';
        $reglas = ['imagen' => ['nullable', 'string', 'max:255', 'regex:~^(https://[^\\s]+|/(?!/)[^\\s]*)$~'], 'modelo_3d' => ['nullable', 'string', 'max:255', 'regex:~^(https://[^\\s]+|/(?!/)[^\\s]*)\\.glb$~'], 'requiere_cocina' => ['sometimes', 'boolean'], 'es_insumo' => ['sometimes', 'boolean'], 'unidad' => ['sometimes', 'string', 'max:20'], 'categoria_id' => ['nullable', Rule::exists('categorias', 'id')->where('restaurante_id', $request->user()->restaurante_id)], 'nombre' => [$obligatorio, 'string', 'max:150'], 'descripcion' => ['nullable', 'string', 'max:1000'], 'precio' => [$obligatorio, 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'], 'costo' => ['sometimes', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'], 'stock_minimo' => ['sometimes', 'integer', 'min:0'], 'activo' => ['sometimes', 'boolean'], 'stock' => $parcial ? ['prohibited'] : ['sometimes', 'integer', 'min:0']];

        $reglas['foto'] = ['sometimes', 'required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', 'dimensions:max_width=6000,max_height=6000'];
        $datos = $request->validate($reglas, ['stock.prohibited' => 'El stock se cambia desde /api/stock/movimientos.']);
        if ($request->hasFile('foto')) {
            $ruta = $request->file('foto')->store('fotos-platos', 'public');
            abort_unless(is_string($ruta), 500, 'No se pudo guardar la foto.');
            $datos['imagen'] = '/fotos-platos/'.basename($ruta);
        }
        unset($datos['foto']);

        return $datos;
    }
}
