<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sammelrechnungen: eine Rechnung für mehrere Monate und/oder mehrere Zähler eines Mieters.
 * Die Sammelrechnung ist selbst eine Zeile in "settlements" (type = collective, ohne Zähler),
 * die zugehörigen Monatsabrechnungen hängen über "collective_items" daran.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlements', function (Blueprint $table) {
            $table->unsignedBigInteger('meter_id')->nullable()->change();
        });

        Schema::create('collective_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collective_id')->constrained('settlements')->cascadeOnDelete();
            $table->foreignId('settlement_id')->constrained('settlements')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['collective_id', 'settlement_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collective_items');

        Schema::table('settlements', function (Blueprint $table) {
            $table->unsignedBigInteger('meter_id')->nullable(false)->change();
        });
    }
};
