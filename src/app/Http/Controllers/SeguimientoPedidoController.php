<?php

namespace App\Http\Controllers;

use App\Models\Pedido;
use App\Models\Restaurante;
use App\Support\RestauranteContexto;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class SeguimientoPedidoController extends Controller
{
    public function show(Request $request, string $slug): JsonResponse
    {
        $datos = $request->validate(['token' => ['required', 'string', 'max:2000']]);
        try {
            $contenido = json_decode(Crypt::decryptString($datos['token']), true);
        } catch (DecryptException $exception) {
            abort(404);
        }
        $restaurante = Restaurante::where('slug', $slug)->where('activo', true)->firstOrFail();
        abort_unless(is_array($contenido) && ($contenido['restaurante_id'] ?? null) === $restaurante->id && ($contenido['expira'] ?? 0) > now()->timestamp, 404);

        return app(RestauranteContexto::class)->ejecutar($restaurante->id, function () use ($contenido): JsonResponse {
            $pedido = Pedido::findOrFail($contenido['pedido_id']);

            return response()->json(['pedido_id' => $pedido->id, 'estado' => $pedido->estado, 'total' => $pedido->total, 'pagado' => $pedido->pagado, 'tipo' => $pedido->tipo])->header('Cache-Control', 'no-store');
        });
    }
}
