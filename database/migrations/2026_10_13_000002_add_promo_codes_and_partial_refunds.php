<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('booking_promo_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('label', 180)->nullable();
            $table->string('discount_type', 20);
            $table->unsignedBigInteger('discount_value');
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedSmallInteger('max_per_client')->default(1);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['is_active', 'starts_at', 'ends_at']);
        });
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('promo_code_id')->nullable()->constrained('booking_promo_codes')->nullOnDelete();
        });
        Schema::table('booking_refunds', function (Blueprint $table) {
            $table->boolean('cancels_booking')->default(true);
        });
        Schema::create('booking_promo_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promo_code_id')->constrained('booking_promo_codes')->restrictOnDelete();
            $table->foreignId('booking_id')->unique()->constrained('bookings')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('discount_irr');
            $table->timestamps();
            $table->unique(['promo_code_id', 'client_id', 'booking_id'], 'promo_client_booking_unique');
            $table->index(['promo_code_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_promo_redemptions');
        Schema::table('booking_refunds', fn (Blueprint $table) => $table->dropColumn('cancels_booking'));
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('promo_code_id');
        });
        Schema::dropIfExists('booking_promo_codes');
    }
};
