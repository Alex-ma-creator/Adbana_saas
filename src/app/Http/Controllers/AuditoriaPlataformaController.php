<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditoriaPlataformaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['accion' => ['nullable', 'string', 'max:60']]);

        return response()->json(DB::table('eventos_plataforma')->select('id', 'actor', 'accion', 'recurso', 'recurso_id', 'motivo', 'created_at')->when($request->filled('accion'), fn ($q) => $q->where('accion', $request->input('accion')))->latest('id')->paginate(25));
    }
}
