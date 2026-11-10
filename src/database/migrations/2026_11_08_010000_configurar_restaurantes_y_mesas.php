<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurantes', function (Blueprint $table): void {
            $table->json('configuracion')->nullable();
            $table->boolean('publicado')->default(false)->index();
        });
        Schema::create('mesas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurante_id')->constrained()->cascadeOnDelete();
            $table->string('nombre', 20);
            $table->string('nombre_key', 20);
            $table->string('zona', 60)->default('Salón');
            $table->boolean('activa')->default(true);
            $table->uuid('codigo')->unique();
            $table->timestamps();
            $table->unique(['restaurante_id', 'nombre_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mesas');
        Schema::table('restaurantes', function (Blueprint $table): void {
            $table->dropColumn(['configuracion', 'publicado']);
        });
    }
};
