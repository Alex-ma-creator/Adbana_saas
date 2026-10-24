<?php

namespace App\Http\Controllers;

use App\Models\Pedido;

class CocinaController extends Controller
{
    public function index()
    {
        // Proyeccion especifica: cocina nunca recibe precios, costos ni datos de contacto.
        $pedidos = Pedido::whereIn('estado', ['pendiente', 'confirmado', 'preparando', 'listo'])
            ->with('detalles:id,pedido_id,nombre_producto,cantidad')
            ->orderBy('id')->paginate(100, ['id', 'tipo', 'mesa', 'estado', 'notas', 'created_at']);
        return $pedidos;
    }
}
