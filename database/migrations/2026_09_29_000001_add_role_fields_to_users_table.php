<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->unique()->after('name');
            $table->string('role', 20)->default('viewer')->after('password');
            $table->string('locale', 5)->default('de')->after('role');
            $table->boolean('is_active')->default(true)->after('locale');
            $table->date('working_month')->nullable()->after('is_active');
            $table->unsignedInteger('legacy_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['username', 'role', 'locale', 'is_active', 'working_month', 'legacy_id']);
        });
    }
};
