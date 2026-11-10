<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos', fn (Blueprint $t) => $t->index(['restaurante_id', 'cuenta_id', 'id'], 'pedidos_cuenta_operativa'));
        Schema::table('detalle_pedidos', fn (Blueprint $t) => $t->index(['pedido_id', 'requiere_cocina'], 'detalle_preparacion'));
    }

    public function down(): void
    {
        Schema::table('detalle_pedidos', fn (Blueprint $t) => $t->dropIndex('detalle_preparacion'));
        Schema::table('pedidos', fn (Blueprint $t) => $t->dropIndex('pedidos_cuenta_operativa'));
    }
};
