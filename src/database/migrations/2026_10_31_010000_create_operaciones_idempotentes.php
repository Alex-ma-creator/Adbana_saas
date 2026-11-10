<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos', fn (Blueprint $t) => $t->string('huella_operacion', 64)->nullable());
        Schema::create('operaciones_idempotentes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('restaurante_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('ambito', 32);
            $t->uuid('clave');
            $t->string('huella', 64);
            $t->json('resultado')->nullable();
            $t->timestamps();
            $t->unique(['restaurante_id', 'user_id', 'ambito', 'clave'], 'operaciones_clave_unica');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operaciones_idempotentes');
        Schema::table('pedidos', fn (Blueprint $t) => $t->dropColumn('huella_operacion'));
    }
};
