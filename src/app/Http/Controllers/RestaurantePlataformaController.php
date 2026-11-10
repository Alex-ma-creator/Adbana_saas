<?php

namespace App\Http\Controllers;

use App\Models\Restaurante;
use App\Models\User;
use App\Support\AdministracionPlataforma;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RestaurantePlataformaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['q' => ['nullable', 'string', 'max:120'], 'activo' => ['nullable', 'in:0,1']]);

        return response()->json(DB::table('restaurantes')->select('id', 'nombre', 'slug', 'telefono', 'activo', 'created_at')
            ->whereNull('restaurantes.deleted_at')
            ->selectSub(DB::table('users')->selectRaw('COUNT(*)')->whereColumn('users.restaurante_id', 'restaurantes.id'), 'usuarios')
            ->selectSub(DB::table('pedidos')->selectRaw('MAX(created_at)')->whereColumn('pedidos.restaurante_id', 'restaurantes.id'), 'ultimo_pedido_at')
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($q) => $q->whereLike('nombre', '%'.$request->input('q').'%')->orWhereLike('slug', '%'.$request->input('q').'%')))
            ->when($request->filled('activo'), fn ($q) => $q->where('activo', (bool) $request->input('activo')))->latest('id')->paginate(20));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $datos = $request->validate(['activo' => ['required', 'boolean'], 'password_actual' => ['required', 'string'], 'motivo' => ['required', 'string', 'min:5', 'max:500']]);
        $r = app(AdministracionPlataforma::class)->bloquear($request, 'gestionar_restaurantes', function ($actor) use ($request, $id, $datos) {
            $r = Restaurante::lockForUpdate()->findOrFail($id);
            $antes = $r->only(['id', 'nombre', 'activo']);
            $r->update(['activo' => $datos['activo']]);
            app(AdministracionPlataforma::class)->registrar($actor, 'cambiar_estado_restaurante', 'restaurante', $id, $antes, $r->only(['id', 'nombre', 'activo']), $datos['motivo'], $request->ip());

            return $r;
        });

        return response()->json($r->only(['id', 'nombre', 'slug', 'activo']));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $datos = $request->validate(['password_actual' => ['required', 'string'], 'motivo' => ['required', 'string', 'min:5', 'max:500'], 'nombre_confirmacion' => ['required', 'string', 'max:120']]);
        app(AdministracionPlataforma::class)->bloquear($request, 'eliminar_restaurantes', function ($actor) use ($request, $id, $datos): void {
            $restaurante = Restaurante::withTrashed()->lockForUpdate()->findOrFail($id);
            abort_unless($datos['nombre_confirmacion'] === $restaurante->nombre, 422, 'Escribe el nombre exacto del restaurante para confirmar.');
            if ($restaurante->trashed()) {
                return;
            }
            $antes = $restaurante->only(['id', 'nombre', 'slug', 'activo']);
            $restaurante->update(['activo' => false]);
            $restaurante->delete();
            DB::table('personal_access_tokens')->where('tokenable_type', (new User)->getMorphClass())
                ->whereIn('tokenable_id', User::where('restaurante_id', $id)->select('id'))->delete();
            app(AdministracionPlataforma::class)->registrar($actor, 'eliminar_restaurante', 'restaurante', $id, $antes, ['deleted_at' => $restaurante->deleted_at->toIso8601String()], $datos['motivo'], $request->ip());
            DB::afterCommit(fn () => Cache::forget('carta:'.$restaurante->slug));
        });

        return response()->json(['message' => 'Restaurante eliminado del servicio. Se conservan los datos históricos y la auditoría.']);
    }
}
