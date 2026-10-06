<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meters', function (Blueprint $table) {
            // electricity | cold_water | hot_water – Wasserzählerstände werden in Litern gespeichert.
            $table->string('medium', 20)->default('electricity')->after('number')->index();
            $table->unsignedSmallInteger('calibration_year')->nullable()->after('location');
        });
    }

    public function down(): void
    {
        Schema::table('meters', function (Blueprint $table) {
            $table->dropIndex(['medium']);
            $table->dropColumn(['medium', 'calibration_year']);
        });
    }
};
