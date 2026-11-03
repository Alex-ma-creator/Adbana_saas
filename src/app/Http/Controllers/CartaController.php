<?php

namespace App\Http\Controllers;

use App\Models\Categoria;
use App\Models\DetallePedido;
use App\Models\MovimientoStock;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Restaurante;
use App\Support\Money;
use App\Support\PedidoPublicoIdempotente;
use App\Support\RestauranteContexto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CartaController extends Controller
{
    public function mostrar(string $slug)
    {
        $carta = Cache::remember("carta:$slug", 15, function () use ($slug) {
            $restaurante = Restaurante::where('slug', $slug)->where('activo', true)->first();

            if (! $restaurante) {
                return null;
            }

            $categorias = Categoria::withoutGlobalScopes()
                ->where('restaurante_id', $restaurante->id)
                ->orderBy('orden')->orderBy('nombre')
                ->get(['id', 'nombre']);

            $productos = Producto::withoutGlobalScopes()
                ->where('restaurante_id', $restaurante->id)
                ->where('activo', true)
                ->orderBy('nombre')
                ->get(['id', 'categoria_id', 'nombre', 'descripcion', 'precio', 'imagen', 'stock']);

            $menu = [];

            foreach ($categorias as $categoria) {
                $items = $productos->where('categoria_id', $categoria->id);

                if ($items->isNotEmpty()) {
                    $menu[] = [
                        'id'        => $categoria->id,
                        'nombre'    => $categoria->nombre,
                        'productos' => $this->formatear($items),
                    ];
                }
            }

            $sinCategoria = $productos->whereNull('categoria_id');

            if ($sinCategoria->isNotEmpty()) {
                $menu[] = [
                    'id'        => null,
                    'nombre'    => 'Otros',
                    'productos' => $this->formatear($sinCategoria),
                ];
            }

            return [
                'restaurante' => [
                    'nombre'   => $restaurante->nombre,
                    'slug'     => $restaurante->slug,
                    'telefono' => $restaurante->telefono,
                ],
                'carta' => $menu,
            ];
        });

        abort_if($carta === null, 404);

        return response()->json($carta);
    }

    public function crearPedido(Request $request, string $slug)
    {
        $restauranteMeta = Cache::remember("restaurante:meta:$slug", 600, function () use ($slug) {
            $r = Restaurante::where('slug', $slug)->where('activo', true)->first(['id', 'slug']);
            return $r ? ['id' => (int) $r->id, 'slug' => (string) $r->slug] : null;
        });
        abort_if(! $restauranteMeta, 404);
        $restauranteId = (int) $restauranteMeta['id'];

        $datos = $request->validate([
            'clave_operacion'     => ['required', 'uuid'],
            'cliente_nombre'      => ['required', 'string', 'max:120'],
            'cliente_telefono'    => ['required_if:tipo,delivery', 'nullable', 'string', 'max:30'],
            'tipo'                => ['required', 'in:mesa,delivery,recojo'],
            'mesa'                => ['required_if:tipo,mesa', 'nullable', 'string', 'max:20'],
            'direccion'           => ['required_if:tipo,delivery', 'nullable', 'string', 'max:255'],
            'notas'               => ['nullable', 'string', 'max:500'],
            'items'               => ['required', 'array', 'min:1', 'max:50'],
            'items.*.producto_id' => ['required', 'integer'],
            'items.*.cantidad'    => ['required', 'integer', 'min:1', 'max:100'],
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

        $pedido = app(RestauranteContexto::class)->ejecutar($restauranteId, function () use ($datos, $restauranteId, $cantidades, $slug) {
            return app(PedidoPublicoIdempotente::class)->ejecutar(
                $restauranteId, $datos['clave_operacion'], $datos,
                function () use ($datos, $restauranteId, $cantidades, $slug) {
                    $productos = Producto::where('activo', true)->whereIn('id', $cantidades->keys())
                        ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                    if ($productos->count() !== $cantidades->count()) {
                        throw ValidationException::withMessages(['items' => 'Hay productos que ya no están disponibles.']);
                    }

                    $totalCents = 0;
                    $itemsValidados = [];
                    foreach ($cantidades as $productoId => $cantidad) {
                        $producto = $productos[$productoId];
                        if ($producto->stock < $cantidad) {
                            throw ValidationException::withMessages(['items' => "Stock insuficiente de {$producto->nombre}."]);
                        }
                        $subtotal = Money::cents($producto->precio) * $cantidad;
                        $totalCents += $subtotal;
                        if ($totalCents > 9999999999) {
                            throw ValidationException::withMessages(['items' => 'El total excede el máximo permitido.']);
                        }
                        $itemsValidados[] = [
                            'producto' => $producto,
                            'cantidad' => $cantidad,
                            'subtotal' => $subtotal,
                        ];
                    }

                    // Crea el pedido directamente con el total definitivo (ahorra 1 UPDATE en BD)
                    $pedido = Pedido::create(collect($datos)->except(['items', 'clave_operacion'])->all() + [
                        'restaurante_id' => $restauranteId,
                        'estado'         => 'pendiente',
                        'total'          => Money::decimal($totalCents),
                    ]);

                    $ahora = now();
                    $detallesRows = [];
                    $movimientosRows = [];
                    $detallesColeccion = collect();
                    $agotoStock = false;

                    foreach ($itemsValidados as $item) {
                        $producto = $item['producto'];
                        $cantidad = $item['cantidad'];
                        DB::table('productos')->where('id', $producto->id)->decrement('stock', $cantidad);
                        if (($producto->stock - $cantidad) <= 0) {
                            $agotoStock = true;
                        }

                        $subtotalDec = Money::decimal($item['subtotal']);
                        $detallesRows[] = [
                            'pedido_id'       => $pedido->id,
                            'producto_id'     => $producto->id,
                            'nombre_producto' => $producto->nombre,
                            'cantidad'        => $cantidad,
                            'precio_unitario' => $producto->precio,
                            'costo_unitario'  => $producto->costo,
                            'subtotal'        => $subtotalDec,
                            'created_at'      => $ahora,
                            'updated_at'      => $ahora,
                        ];

                        $detallesColeccion->push(new DetallePedido([
                            'pedido_id'       => $pedido->id,
                            'producto_id'     => $producto->id,
                            'nombre_producto' => $producto->nombre,
                            'cantidad'        => $cantidad,
                            'precio_unitario' => $producto->precio,
                            'costo_unitario'  => $producto->costo,
                            'subtotal'        => $subtotalDec,
                        ]));

                        $movimientosRows[] = [
                            'restaurante_id' => $restauranteId,
                            'producto_id'    => $producto->id,
                            'pedido_id'      => $pedido->id,
                            'tipo'           => 'salida',
                            'cantidad'       => $cantidad,
                            'motivo'         => 'pedido',
                            'created_at'      => $ahora,
                            'updated_at'      => $ahora,
                        ];
                    }

                    DetallePedido::insert($detallesRows);
                    MovimientoStock::insert($movimientosRows);

                    // Si algún producto se agotó por completo, invalidar caché de la carta pública
                    if ($agotoStock) {
                        Cache::forget("carta:$slug");
                    }

                    // Adjunta la relación directamente sin necesidad de enviar otro SELECT a PostgreSQL
                    $pedido->setRelation('detalles', $detallesColeccion);
                    return $pedido;
                }
            );
        });

        return response()->json([
            'pedido_id' => $pedido->id,
            'estado'    => $pedido->estado,
            'total'     => $pedido->total,
            'items'     => $pedido->detalles->map(fn ($d) => [
                'producto'        => $d->nombre_producto,
                'cantidad'        => $d->cantidad,
                'precio_unitario' => $d->precio_unitario,
                'subtotal'        => $d->subtotal,
            ]),
        ], 201);
    }

    private function formatear($productos): array
    {
        return $productos->map(fn ($p) => [
            'id'          => $p->id,
            'nombre'      => $p->nombre,
            'descripcion' => $p->descripcion,
            'precio'      => $p->precio,
            'imagen'      => $p->imagen,
            'disponible'  => $p->stock > 0,
        ])->values()->all();
    }
}