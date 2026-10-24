<?php

namespace App\Http\Controllers;

use App\Models\Gasto;
use Illuminate\Http\Request;

class GastoController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'desde'     => ['sometimes', 'date'],
            'hasta'     => ['sometimes', 'date'],
            'categoria' => ['sometimes', 'string', 'max:50'],
        ]);

        return Gasto::query()
            ->when($request->filled('desde'), fn ($q) => $q->whereDate('fecha', '>=', $request->input('desde')))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('fecha', '<=', $request->input('hasta')))
            ->when($request->filled('categoria'), fn ($q) => $q->where('categoria', $request->input('categoria')))
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->paginate(20);
    }

    public function store(Request $request)
    {
        $datos = $request->validate([
            'descripcion' => ['required', 'string', 'max:255'],
            'categoria'   => ['nullable', 'string', 'max:50'],
            'monto'       => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'fecha'       => ['sometimes', 'date'],
        ]);

        $datos['fecha'] = $datos['fecha'] ?? now()->toDateString();
        $datos['user_id'] = $request->user()->id;

        return response()->json(Gasto::create($datos), 201);
    }
}