<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meters', function (Blueprint $table) {
            // Messpunkt des Analysators auf einem Abzweig: wird abgelesen, aber nicht abgerechnet.
            $table->boolean('is_analyzer')->default(false)->after('is_main');
            // Leitungsschema: vorgeschalteter Zähler (Abzweig). Leer = direkt am Hauptzähler (parent_id).
            $table->foreignId('feed_id')->nullable()->after('parent_id')->constrained('meters')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('meters', function (Blueprint $table) {
            $table->dropConstrainedForeignId('feed_id');
            $table->dropColumn('is_analyzer');
        });
    }
};
