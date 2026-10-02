<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // false = Pauschalmieter: Abrechnung nur zur Kontrolle, nie eine Rechnung mit Nummer.
            $table->boolean('issues_invoices')->default(true)->after('price_factor');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('issues_invoices');
        });
    }
};
