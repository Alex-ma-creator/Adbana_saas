<?php

namespace App\Support;

use App\Models\Cobro;
use App\Models\Pedido;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class LibroCobros
{
    public static function consulta(string $inicio, string $fin): Builder
    {
        $nuevos = Cobro::where('created_at', '>=', $inicio)->where('created_at', '<', $fin)->selectRaw("pedido_id, caja_id, user_id, medio_pago, CASE WHEN tipo = 'cobro' THEN monto ELSE -monto END as monto, created_at")->toBase();
        $historicos = Pedido::whereNotNull('cobrado_at')->where('cobrado_at', '>=', $inicio)->where('cobrado_at', '<', $fin)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('cobros')->whereColumn('cobros.pedido_id', 'pedidos.id'))->selectRaw('id as pedido_id, caja_id, user_id, medio_pago, total as monto, cobrado_at as created_at')->toBase();

        return DB::query()->fromSub($nuevos->unionAll($historicos), 'libro');
    }
}
