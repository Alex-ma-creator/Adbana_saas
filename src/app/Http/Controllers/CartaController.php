<?php

namespace App\Http\Controllers;

use App\Models\Categoria;
use App\Models\DetallePedido;
use App\Models\Mesa;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Restaurante;
use App\Support\CuentasMesa;
use App\Support\InventarioPedido;
use App\Support\Money;
use App\Support\PedidoPublicoIdempotente;
use App\Support\RestauranteContexto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

class CartaController extends Controller
{
    public function mostrar(Request $request, string $slug)
    {
        abort_unless(Restaurante::where('slug', $slug)->where('activo', true)->exists(), 404);
        $carta = Cache::remember("carta:$slug", 60, function () use ($slug) {
            $restaurante = Restaurante::where('slug', $slug)->where('activo', true)->first();

            if (! $restaurante) {
                return null;
            }

            return app(RestauranteContexto::class)->ejecutar($restaurante->id, function () use ($restaurante) {
                $categorias = Categoria::withoutGlobalScopes()
                    ->where('restaurante_id', $restaurante->id)
                    ->orderBy('orden')->orderBy('nombre')
                    ->get(['id', 'nombre']);

                $productos = Producto::withoutGlobalScopes()->with('receta.insumo')
                    ->where('restaurante_id', $restaurante->id)
                    ->where('activo', true)->where('es_insumo', false)
                    ->orderBy('nombre')
                    ->get(['id', 'categoria_id', 'nombre', 'descripcion', 'precio', 'imagen', 'modelo_3d', 'stock']);

                $menu = [];

                foreach ($categorias as $categoria) {
                    $items = $productos->where('categoria_id', $categoria->id);

                    if ($items->isNotEmpty()) {
                        $menu[] = [
                            'id' => $categoria->id,
                            'nombre' => $categoria->nombre,
                            'productos' => $this->formatear($items, $restaurante->slug),
                        ];
                    }
                }

                $sinCategoria = $productos->whereNull('categoria_id');

                if ($sinCategoria->isNotEmpty()) {
                    $menu[] = [
                        'id' => null,
                        'nombre' => 'Otros',
                        'productos' => $this->formatear($sinCategoria, $restaurante->slug),
                    ];
                }

                return [
                    'restaurante' => [
                        'nombre' => $restaurante->nombre,
                        'slug' => $restaurante->slug,
                        'telefono' => $restaurante->telefono,
                        'configuracion' => $restaurante->configuracion,
                    ],
                    'carta' => $menu,
                ];
            });
        });

        abort_if($carta === null, 404);
        if ($request->filled('mesa')) {
            $request->validate(['mesa' => ['uuid']]);
            $restauranteId = Restaurante::where('slug', $slug)->where('activo', true)->value('id');
            $mesa = Mesa::withoutGlobalScopes()->where('restaurante_id', $restauranteId)->where('codigo', $request->string('mesa'))->where('activa', true)->firstOrFail();
            $carta['mesa'] = ['nombre' => $mesa->nombre, 'codigo' => $mesa->codigo];
        }

        return response()->json($carta);
    }

    public function crearPedido(Request $request, string $slug)
    {
        $restaurante = Restaurante::where('slug', $slug)->where('activo', true)->firstOrFail();

        $datos = $request->validate([
            'clave_operacion' => ['required', 'uuid'],
            'cliente_nombre' => ['required', 'string', 'max:120'],
            'cliente_telefono' => ['required_if:tipo,delivery', 'nullable', 'string', 'max:30'],
            'tipo' => ['required', 'in:mesa,delivery,recojo'],
            'mesa' => ['required_if:tipo,mesa', 'nullable', 'string', 'max:20'],
            'direccion' => ['required_if:tipo,delivery', 'nullable', 'string', 'max:255'],
            'notas' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.producto_id' => ['required', 'integer'],
            'items.*.cantidad' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        // Une productos repetidos y los ordena por id (evita bloqueos cruzados entre pedidos).
        $cantidades = collect($datos['items'])
            ->groupBy('producto_id')
            ->map(fn ($grupo) => (int) $grupo->sum('cantidad'))
            ->sortKeys();

        if ($cantidades->contains(fn (int $cantidad) => $cantidad > 100)) {
            throw ValidationException::withMessages([
                'items' => 'La cantidad máxima por producto es 100.',
            ]);
        }

        $pedido = app(RestauranteContexto::class)->ejecutar($restaurante->id, function () use ($datos, $restaurante, $cantidades, $slug) {
            return app(PedidoPublicoIdempotente::class)->ejecutar(
                $restaurante->id, $datos['clave_operacion'], $datos,
                function () use ($datos, $restaurante, $cantidades, $slug) {
                    $modalidades = $restaurante->configuracion['modalidades'] ?? ['mesa', 'recojo', 'delivery'];
                    if (! in_array($datos['tipo'], $modalidades, true)) {
                        throw ValidationException::withMessages(['tipo' => 'El restaurante no ofrece esta modalidad.']);
                    }

                    $productos = Producto::where('activo', true)->where('es_insumo', false)->whereIn('id', $cantidades->keys())
                        ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                    if ($productos->count() !== $cantidades->count()) {
                        throw ValidationException::withMessages(['items' => 'Hay productos que ya no están disponibles.']);
                    }

                    $cuenta = $datos['tipo'] === 'mesa' ? app(CuentasMesa::class)->abrir($datos['mesa']) : null;

                    $totalCents = 0;
                    foreach ($cantidades as $productoId => $cantidad) {
                        $producto = $productos[$productoId];
                        $subtotal = Money::cents($producto->precio) * $cantidad;
                        $totalCents += $subtotal;
                        if ($totalCents > 9999999999) {
                            throw ValidationException::withMessages(['items' => 'El total excede el máximo permitido.']);
                        }
                    }

                    $pedido = Pedido::create(collect($datos)->except(['items', 'clave_operacion'])->all() + [
                        'restaurante_id' => $restaurante->id,
                        'estado'         => 'pendiente',
                        'total'          => Money::decimal($totalCents),
                        'cuenta_id'      => $cuenta?->id,
                    ]);

                    $costos = app(InventarioPedido::class)->descontar($pedido, $cantidades, null, 'pedido');

                    foreach ($cantidades as $productoId => $cantidad) {
                        $producto = $productos[$productoId];
                        $subtotal = Money::cents($producto->precio) * $cantidad;
                        DetallePedido::create([
                            'pedido_id'       => $pedido->id,
                            'producto_id'     => $producto->id,
                            'nombre_producto' => $producto->nombre,
                            'cantidad'        => $cantidad,
                            'precio_unitario' => $producto->precio,
                            'costo_unitario'  => $costos[$productoId],
                            'requiere_cocina' => $producto->requiere_cocina,
                            'subtotal'        => Money::decimal($subtotal),
                        ]);
                    }

                    return $pedido->load('detalles');
                }
            );
        });

        return response()->json([
            'seguimiento_token' => Crypt::encryptString(json_encode(['pedido_id' => $pedido->id, 'restaurante_id' => $restaurante->id, 'expira' => now()->addDays(2)->timestamp], JSON_THROW_ON_ERROR)),
            'pedido_id' => $pedido->id,
            'estado' => $pedido->estado,
            'total' => $pedido->total,
            'items' => $pedido->detalles->map(fn ($d) => [
                'producto' => $d->nombre_producto,
                'cantidad' => $d->cantidad,
                'precio_unitario' => $d->precio_unitario,
                'subtotal' => $d->subtotal,
            ]),
        ], 201);
    }

    private function formatear($productos, string $slug): array
    {
        return $productos->map(fn ($p) => [
            'id' => $p->id,
            'nombre' => $p->nombre,
            'descripcion' => $p->descripcion,
            'precio' => $p->precio,
            'imagen' => $p->imagen,
            'modelo_3d' => $p->modelo_3d && str_starts_with($p->modelo_3d, '/') ? '/api/carta/'.rawurlencode($slug).'/productos/'.$p->id.'/modelo' : $p->modelo_3d,
            'disponible' => $p->stock_disponible > 0,
        ])->values()->all();
    }
}
