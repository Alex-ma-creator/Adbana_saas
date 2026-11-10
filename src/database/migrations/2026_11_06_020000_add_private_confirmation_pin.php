<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('administradores_plataforma', function (Blueprint $table): void {
            $table->string('pin_confirmacion')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('administradores_plataforma', function (Blueprint $table): void {
            $table->dropColumn('pin_confirmacion');
        });
    }
};
