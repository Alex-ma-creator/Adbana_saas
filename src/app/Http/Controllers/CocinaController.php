<?php

namespace App\Http\Controllers;

use App\Models\Pedido;
use Illuminate\Http\Request;

class CocinaController extends Controller
{
    public function index(Request $request)
    {
        // Proyeccion especifica: cocina nunca recibe precios, costos ni datos de contacto.
        $request->validate(['q' => ['sometimes', 'nullable', 'string', 'max:120']]);
        $pedidos = Pedido::when($request->filled('q'), fn ($q) => $q->where(function ($q) use ($request) {
            $term = '%'.$request->input('q').'%';
            $q->whereLike('mesa', $term)->orWhereHas('detalles', fn ($d) => $d->whereLike('nombre_producto', $term));
        }))->whereHas('detalles', fn ($q) => $q->where('requiere_cocina', true))->whereIn('estado', ['pendiente', 'confirmado', 'preparando', 'listo'])->with(['detalles' => fn ($q) => $q->where('requiere_cocina', true)->select('id', 'pedido_id', 'nombre_producto', 'cantidad')])->orderBy('id')->paginate(50, ['id', 'tipo', 'mesa', 'estado', 'notas', 'created_at']);

        $pedidos->getCollection()->each->setAppends([]);

        return $pedidos;
    }
}
