<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Position eines Zählers auf einem Plan des Objekts (in % von Breite und Höhe).
        Schema::create('site_plan_markers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meter_id')->constrained()->cascadeOnDelete();
            $table->decimal('x', 6, 3);
            $table->decimal('y', 6, 3);
            $table->timestamps();

            $table->unique(['site_plan_id', 'meter_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_plan_markers');
    }
};
