<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedidos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurante_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cliente_nombre');
            $table->string('cliente_telefono', 30)->nullable();
            $table->string('tipo', 20)->default('recojo');      // mesa, delivery, recojo
            $table->string('mesa', 20)->nullable();
            $table->string('direccion')->nullable();
            $table->string('estado', 20)->default('pendiente'); // pendiente, confirmado, preparando, listo, entregado, cancelado
            $table->string('medio_pago', 20)->nullable();       // efectivo, yape, plin, tarjeta
            $table->decimal('total', 10, 2)->default(0);
            $table->text('notas')->nullable();
            $table->timestamps();

            $table->index(['restaurante_id', 'estado']);
            $table->index(['restaurante_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedidos');
    }
};