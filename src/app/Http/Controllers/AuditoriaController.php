<?php

namespace App\Http\Controllers;

use App\Models\Auditoria;
use Illuminate\Http\Request;

class AuditoriaController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'tabla'    => ['sometimes', 'string', 'max:50'],
            'user_id'  => ['sometimes', 'integer'],
            'desde'    => ['sometimes', 'date'],
            'hasta'    => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = $request->integer('per_page', 20);

        return Auditoria::with('user:id,name,rol')
            ->when($request->filled('tabla'), fn ($q) => $q->where('tabla', $request->input('tabla')))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('desde'), fn ($q) => $q->where('created_at', '>=', \Carbon\CarbonImmutable::parse($request->input('desde'))->startOfDay()))
            ->when($request->filled('hasta'), fn ($q) => $q->where('created_at', '<', \Carbon\CarbonImmutable::parse($request->input('hasta'))->startOfDay()->addDay()))
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
