<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('productos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurante_id')->constrained()->cascadeOnDelete();
            $table->foreignId('categoria_id')->nullable()
                  ->constrained('categorias')->nullOnDelete();
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->decimal('precio', 10, 2);
            $table->decimal('costo', 10, 2)->default(0);
            $table->integer('stock')->default(0);
            $table->integer('stock_minimo')->default(0);
            $table->string('imagen')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index(['restaurante_id', 'activo']);
            $table->index(['restaurante_id', 'categoria_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('productos');
    }
};