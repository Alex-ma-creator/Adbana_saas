<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurantes', function (Blueprint $table): void {
            $table->softDeletes();
            $table->index(['deleted_at', 'activo', 'id'], 'restaurantes_estado_servicio');
        });
    }

    public function down(): void
    {
        Schema::table('restaurantes', function (Blueprint $table): void {
            $table->dropIndex('restaurantes_estado_servicio');
            $table->dropSoftDeletes();
        });
    }
};
