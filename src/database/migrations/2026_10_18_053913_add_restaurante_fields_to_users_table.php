<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('restaurante_id')->nullable()->after('id')
                  ->constrained('restaurantes')->cascadeOnDelete();
            $table->string('rol', 20)->default('cajero')->after('password');
            $table->boolean('activo')->default(true)->after('rol');
            $table->index(['restaurante_id', 'rol']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('restaurante_id');
            $table->dropColumn(['rol', 'activo']);
        });
    }
};