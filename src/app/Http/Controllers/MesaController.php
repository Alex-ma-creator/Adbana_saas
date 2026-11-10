<?php

namespace App\Http\Controllers;

use App\Models\Pedido;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

class MesaController extends Controller
{
    public function index(Request $request): LengthAwarePaginator
    {
        $request->validate(['q' => ['sometimes', 'string', 'max:20']]);

        return Pedido::where('tipo', 'mesa')->where('estado', '!=', 'cancelado')->where(fn ($q) => $q->where('estado', '!=', 'entregado')->orWhereColumn('pagado', '<', 'total'))->when($request->filled('q'), fn ($q) => $q->whereLike('mesa', '%'.$request->input('q').'%'))->select('mesa')->selectRaw('COUNT(*) as pedidos, SUM(CASE WHEN cobrado_at IS NULL THEN total - pagado ELSE 0 END) as pendiente')->groupBy('mesa')->orderBy('mesa')->paginate(30)->through(fn ($mesa) => ['mesa' => $mesa->mesa, 'pedidos' => (int) $mesa->pedidos, 'pendiente' => Money::decimal(Money::cents($mesa->pendiente))]);
    }
}
