<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('debtor_number')->nullable()->index();
            $table->string('street')->nullable();
            $table->string('zip', 10)->nullable();
            $table->string('city')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            // Individueller Preisfaktor; null = Standardwert aus den Einstellungen.
            $table->decimal('price_factor', 5, 3)->nullable();
            $table->boolean('send_invoices_by_email')->default(false);
            $table->boolean('is_active')->default(true);
            $table->date('active_from')->nullable();
            $table->unsignedInteger('legacy_id')->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('meters', function (Blueprint $table) {
            $table->id();
            $table->string('number');
            $table->string('location')->nullable();
            $table->unsignedSmallInteger('factor')->default(1);
            $table->boolean('is_main')->default(false);
            $table->foreignId('parent_id')->nullable()->constrained('meters')->nullOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->string('qr_token', 64)->unique();
            // md5-Hash aus dem Altsystem, damit bereits aufgeklebte QR-Codes weiter funktionieren.
            $table->char('legacy_hash', 32)->nullable()->unique();
            $table->foreignId('replaced_by_id')->nullable()->constrained('meters')->nullOnDelete();
            $table->date('installed_on')->nullable();
            $table->date('removed_on')->nullable();
            $table->unsignedInteger('legacy_id')->nullable()->unique();
            $table->timestamps();
        });

        // Historie der Zuordnung Zähler <-> Mieter (Mieterwechsel).
        Schema::create('meter_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meter_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->timestamps();

            $table->index(['meter_id', 'starts_on']);
        });

        Schema::create('readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meter_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('value');
            $table->date('read_on');
            // Abrechnungsgrundlage (Altsystem: SETTLED = 1): Anfangsstand einer Abrechnungsperiode.
            $table->boolean('is_base')->default(false);
            $table->string('source', 20)->default('admin');
            $table->string('reader_name')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('status', 20)->default('approved');
            $table->string('check_note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('legacy_id')->nullable()->unique();
            $table->timestamps();

            $table->index(['meter_id', 'read_on']);
            $table->index(['status']);
        });

        // Strompreis je Hauptzähler und Monat (Rechnung des Versorgers).
        Schema::create('electricity_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meter_id')->constrained()->cascadeOnDelete();
            $table->date('month');
            $table->string('supplier_invoice_number')->nullable();
            $table->decimal('consumption_kwh', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2)->default(0);
            $table->decimal('net_price', 10, 5);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('legacy_id')->nullable()->unique();
            $table->timestamps();

            $table->unique(['meter_id', 'month']);
        });

        Schema::create('settlements', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->default('invoice');
            $table->unsignedInteger('invoice_number')->nullable()->unique();
            $table->date('invoice_date')->nullable();
            $table->date('period');
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('meter_id')->constrained()->restrictOnDelete();
            $table->foreignId('main_meter_id')->nullable()->constrained('meters')->nullOnDelete();
            $table->foreignId('start_reading_id')->nullable()->constrained('readings')->nullOnDelete();
            $table->foreignId('end_reading_id')->nullable()->constrained('readings')->nullOnDelete();
            $table->foreignId('sample_reading_id')->nullable()->constrained('readings')->nullOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->integer('consumption_kwh');
            $table->unsignedSmallInteger('meter_factor')->default(1);
            $table->integer('billed_kwh');
            $table->decimal('base_price', 10, 5);
            $table->decimal('price_factor', 5, 3);
            $table->decimal('unit_price', 10, 2);
            $table->decimal('net_amount', 12, 2);
            $table->decimal('vat_rate', 5, 2);
            $table->decimal('vat_amount', 12, 2);
            $table->decimal('gross_amount', 12, 2);
            $table->boolean('is_invoiced')->default(true);
            $table->foreignId('cancels_id')->nullable()->constrained('settlements')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('emailed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('legacy_id')->nullable()->unique();
            $table->timestamps();

            $table->index(['period', 'meter_id']);
        });

        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 20);
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->json('changes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
        Schema::dropIfExists('settlements');
        Schema::dropIfExists('electricity_prices');
        Schema::dropIfExists('readings');
        Schema::dropIfExists('meter_assignments');
        Schema::dropIfExists('meters');
        Schema::dropIfExists('tenants');
        Schema::dropIfExists('settings');
    }
};
