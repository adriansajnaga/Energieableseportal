<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rzut obiektu / Lageplan zum Leitungsschema (Bild oder PDF, privat gespeichert).
        Schema::create('site_plans', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->unsignedSmallInteger('position')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_plans');
    }
};
