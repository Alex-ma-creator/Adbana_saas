<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('administradores_plataforma', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->string('email')->unique();
            $t->string('password');
            $t->string('rol', 24);
            $t->boolean('activo')->default(true);
            $t->json('permisos')->nullable();
            $t->timestamp('ultimo_acceso_at')->nullable();
            $t->unsignedInteger('sesion_version')->default(0);
            $t->rememberToken();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('control_plataforma', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
        });
        DB::table('control_plataforma')->insert(['id' => 1]);
        Schema::create('eventos_plataforma', function (Blueprint $t) {
            $t->id();
            $t->foreignId('administrador_id')->nullable()->constrained('administradores_plataforma')->restrictOnDelete();
            $t->string('actor', 120);
            $t->string('accion', 60);
            $t->string('recurso', 50);
            $t->unsignedBigInteger('recurso_id')->nullable();
            $t->string('motivo', 500)->nullable();
            $t->json('antes')->nullable();
            $t->json('despues')->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamp('created_at');
            $t->index('created_at');
        });
        Schema::table('pedidos', fn (Blueprint $t) => $t->index(['created_at', 'restaurante_id'], 'pedidos_plataforma_actividad_idx'));
    }

    public function down(): void
    {
        Schema::table('pedidos', fn (Blueprint $t) => $t->dropIndex('pedidos_plataforma_actividad_idx'));
        Schema::dropIfExists('eventos_plataforma');
        Schema::dropIfExists('control_plataforma');
        Schema::dropIfExists('administradores_plataforma');
    }
};
