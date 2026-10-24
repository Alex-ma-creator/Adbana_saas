<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cajas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurante_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->decimal('saldo_inicial', 12, 2);
            $table->decimal('efectivo_contado', 12, 2)->nullable();
            $table->decimal('efectivo_esperado', 12, 2)->nullable();
            $table->decimal('diferencia', 12, 2)->nullable();
            $table->timestamp('cerrada_at')->nullable();
            $table->timestamps();
            $table->index(['restaurante_id', 'user_id', 'cerrada_at']);
        });
        Schema::create('movimientos_caja', function (Blueprint $table) {
            $table->id();
            $table->foreignId('caja_id')->constrained('cajas')->restrictOnDelete();
            $table->string('tipo', 10);
            $table->decimal('monto', 12, 2);
            $table->string('motivo');
            $table->timestamps();
        });
        Schema::table('pedidos', function (Blueprint $table) {
            $table->foreignId('caja_id')->nullable()->constrained('cajas')->restrictOnDelete();
            $table->decimal('recibido', 12, 2)->nullable();
            $table->decimal('vuelto', 12, 2)->nullable();
            $table->timestamp('cobrado_at')->nullable();
            $table->uuid('clave_operacion')->nullable();
            $table->unique(['restaurante_id', 'clave_operacion']);
            $table->index(['caja_id', 'medio_pago']);
        });
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropForeign(['caja_id']);
            $table->dropUnique(['restaurante_id', 'clave_operacion']);
            $table->dropIndex(['caja_id', 'medio_pago']);
            $table->dropColumn(['caja_id', 'recibido', 'vuelto', 'cobrado_at', 'clave_operacion']);
        });
        Schema::dropIfExists('movimientos_caja');
        Schema::dropIfExists('cajas');
    }
};
