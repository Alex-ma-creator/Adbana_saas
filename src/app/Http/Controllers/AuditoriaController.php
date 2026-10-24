<?php

namespace App\Http\Controllers;

use App\Models\Auditoria;
use Illuminate\Http\Request;

class AuditoriaController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'tabla'   => ['sometimes', 'string', 'max:50'],
            'user_id' => ['sometimes', 'integer'],
            'desde'   => ['sometimes', 'date'],
            'hasta'   => ['sometimes', 'date'],
        ]);

        return Auditoria::with('user:id,name,rol')
            ->when($request->filled('tabla'), fn ($q) => $q->where('tabla', $request->input('tabla')))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('desde'), fn ($q) => $q->whereDate('created_at', '>=', $request->input('desde')))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('created_at', '<=', $request->input('hasta')))
            ->orderByDesc('id')
            ->paginate(20);
    }
}