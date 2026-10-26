<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitudes_publicas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurante_id')->constrained()->cascadeOnDelete();
            $table->uuid('clave');
            $table->char('huella', 64);
            $table->foreignId('pedido_id')->nullable()->constrained('pedidos')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['restaurante_id', 'clave']);
        });
        Schema::table('pedidos', function (Blueprint $table) {
            $table->index(['restaurante_id', 'cobrado_at'], 'pedidos_restaurante_cobro_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitudes_publicas');
        Schema::table('pedidos', fn (Blueprint $table) => $table->dropIndex('pedidos_restaurante_cobro_idx'));
    }
};
