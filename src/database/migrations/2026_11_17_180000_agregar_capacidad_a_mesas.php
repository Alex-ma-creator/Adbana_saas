<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mesas', function (Blueprint $table): void {
            $table->unsignedSmallInteger('capacidad')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mesas', function (Blueprint $table): void {
            $table->dropColumn('capacidad');
        });
    }
};
