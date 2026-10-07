<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mobiler Energie-Analysator (ESP32-S3, bis zu 6 Zähler F&F LE-03M über Modbus RTU).
 * Zeiten werden in UTC gespeichert (DATETIME ohne Zeitzonenumrechnung), angezeigt in Europe/Warsaw.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analyzer_devices', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->unique();
            $table->char('token_hash', 64)->unique();
            $table->string('fw', 20)->nullable();
            $table->unsignedInteger('last_boot')->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('analyzer_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('analyzer_devices')->cascadeOnDelete();
            $table->unsignedTinyInteger('slot');
            $table->string('name')->nullable();
            $table->unsignedTinyInteger('addr')->nullable();
            $table->string('model', 20)->nullable();
            // Zuordnung zu einem Zähler (Messpunkt) im Portal: Tageswerte werden als Ablesungen übernommen.
            $table->foreignId('meter_id')->nullable()->constrained('meters')->nullOnDelete();
            $table->dateTime('meter_since')->nullable();
            $table->timestamps();

            $table->unique(['device_id', 'slot']);
        });

        Schema::create('analyzer_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('analyzer_devices')->cascadeOnDelete();
            $table->unsignedTinyInteger('slot');
            $table->unsignedTinyInteger('addr');
            $table->string('model', 20)->nullable();
            $table->string('meter_name')->nullable();
            $table->unsignedInteger('boot');
            $table->unsignedInteger('up');
            $table->dateTime('ts')->nullable();
            $table->boolean('ts_reconstructed')->default(false);
            $table->boolean('needs_review')->default(false);
            $table->string('reason', 10);
            $table->decimal('kwh', 14, 2);
            $table->dateTime('received_at');

            $table->unique(['device_id', 'boot', 'up', 'slot']);
            $table->index(['device_id', 'slot', 'ts']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analyzer_readings');
        Schema::dropIfExists('analyzer_slots');
        Schema::dropIfExists('analyzer_devices');
    }
};
