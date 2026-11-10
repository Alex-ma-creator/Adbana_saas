<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuentas_mesa', function (Blueprint $t) {
            $t->id();
            $t->foreignId('restaurante_id')->constrained()->restrictOnDelete();
            $t->string('mesa', 20);
            $t->string('mesa_key', 80);
            $t->unsignedTinyInteger('abierta')->nullable()->default(1);
            $t->timestamp('cerrada_at')->nullable();
            $t->timestamps();
            $t->unique(['restaurante_id', 'mesa_key', 'abierta']);
            $t->index(['restaurante_id', 'abierta', 'id']);
        });
        Schema::table('pedidos', function (Blueprint $t) {
            $t->foreignId('cuenta_id')->nullable()->constrained('cuentas_mesa')->restrictOnDelete();
            $t->decimal('pagado', 12, 2)->default(0);
            $t->decimal('devuelto', 12, 2)->default(0);
            $t->timestamp('anulado_at')->nullable();
            $t->string('motivo_cancelacion', 500)->nullable();
        });
        Schema::table('productos', function (Blueprint $t) {
            $t->boolean('requiere_cocina')->default(true);
            $t->boolean('es_insumo')->default(false);
            $t->string('unidad', 20)->default('unidad');
        });
        Schema::table('detalle_pedidos', fn (Blueprint $t) => $t->boolean('requiere_cocina')->default(true));
        Schema::create('cobros', function (Blueprint $t) {
            $t->id();
            $t->foreignId('restaurante_id')->constrained()->restrictOnDelete();
            $t->foreignId('pedido_id')->constrained()->restrictOnDelete();
            $t->foreignId('caja_id')->nullable()->constrained('cajas')->restrictOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('tipo', 15)->default('cobro');
            $t->string('medio_pago', 20);
            $t->decimal('monto', 12, 2);
            $t->decimal('recibido', 12, 2)->default(0);
            $t->decimal('vuelto', 12, 2)->default(0);
            $t->string('motivo', 500)->nullable();
            $t->timestamps();
            $t->index(['restaurante_id', 'created_at', 'tipo']);
            $t->index(['caja_id', 'medio_pago', 'tipo']);
        });
        Schema::create('recetas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('restaurante_id')->constrained()->restrictOnDelete();
            $t->foreignId('producto_id')->constrained('productos')->restrictOnDelete();
            $t->foreignId('insumo_id')->constrained('productos')->restrictOnDelete();
            $t->unsignedInteger('cantidad');
            $t->unique(['producto_id', 'insumo_id']);
        });
        Schema::create('consumos_pedido', function (Blueprint $t) {
            $t->id();
            $t->foreignId('restaurante_id')->constrained()->restrictOnDelete();
            $t->foreignId('pedido_id')->constrained()->restrictOnDelete();
            $t->foreignId('producto_id')->constrained('productos')->restrictOnDelete();
            $t->unsignedInteger('cantidad');
            $t->boolean('repuesto')->default(false);
            $t->index(['pedido_id', 'producto_id']);
        });
        Schema::create('eventos_operacion', function (Blueprint $t) {
            $t->id();
            $t->foreignId('restaurante_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('pedido_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('accion', 40);
            $t->string('motivo', 500);
            $t->json('datos')->nullable();
            $t->timestamps();
            $t->index(['restaurante_id', 'created_at']);
        });
        DB::table('pedidos')->whereNotNull('cobrado_at')->orderBy('id')->chunkById(200, function ($pedidos) {
            foreach ($pedidos as $p) {
                DB::table('pedidos')->where('id', $p->id)->update(['pagado' => $p->total]);
                DB::table('cobros')->insert(['restaurante_id' => $p->restaurante_id, 'pedido_id' => $p->id, 'caja_id' => $p->caja_id, 'user_id' => $p->caja_id ? DB::table('cajas')->where('id', $p->caja_id)->value('user_id') : null, 'medio_pago' => $p->medio_pago ?? 'efectivo', 'monto' => $p->total, 'recibido' => $p->recibido ?? $p->total, 'vuelto' => $p->vuelto ?? 0, 'motivo' => 'Migración del cobro histórico', 'created_at' => $p->cobrado_at, 'updated_at' => $p->cobrado_at]);
            }
        });
        DB::table('pedidos')->where('tipo', 'mesa')->whereNotNull('mesa')->where('estado', '!=', 'cancelado')->where(fn ($q) => $q->where('estado', '!=', 'entregado')->orWhereNull('cobrado_at'))->orderBy('id')->chunkById(200, function ($pedidos) {
            foreach ($pedidos as $p) {
                $key = mb_strtolower(trim($p->mesa));
                $c = DB::table('cuentas_mesa')->where('restaurante_id', $p->restaurante_id)->where('mesa_key', $key)->where('abierta', 1)->first();
                $id = $c?->id ?? DB::table('cuentas_mesa')->insertGetId(['restaurante_id' => $p->restaurante_id, 'mesa' => trim($p->mesa), 'mesa_key' => $key, 'abierta' => 1, 'created_at' => $p->created_at, 'updated_at' => now()]);
                DB::table('pedidos')->where('id', $p->id)->update(['cuenta_id' => $id]);
            }
        });
    }

    public function down(): void
    {
        foreach (['eventos_operacion', 'consumos_pedido', 'recetas', 'cobros'] as $tabla) {
            Schema::dropIfExists($tabla);
        }
        Schema::table('detalle_pedidos', fn (Blueprint $t) => $t->dropColumn('requiere_cocina'));
        Schema::table('productos', fn (Blueprint $t) => $t->dropColumn(['requiere_cocina', 'es_insumo', 'unidad']));
        Schema::table('pedidos', function (Blueprint $t) {
            $t->dropConstrainedForeignId('cuenta_id');
            $t->dropColumn(['pagado', 'devuelto', 'anulado_at', 'motivo_cancelacion']);
        });
        Schema::dropIfExists('cuentas_mesa');
    }
};
