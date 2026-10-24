<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gastos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurante_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('descripcion');
            $table->string('categoria', 50)->nullable();
            $table->decimal('monto', 10, 2);
            $table->date('fecha');
            $table->timestamps();

            $table->index(['restaurante_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gastos');
    }
};