<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auditoria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurante_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('accion', 30);                        // crear, actualizar, eliminar
            $table->string('tabla', 50);
            $table->unsignedBigInteger('registro_id')->nullable();
            $table->jsonb('valor_anterior')->nullable();
            $table->jsonb('valor_nuevo')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['restaurante_id', 'tabla', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auditoria');
    }
};