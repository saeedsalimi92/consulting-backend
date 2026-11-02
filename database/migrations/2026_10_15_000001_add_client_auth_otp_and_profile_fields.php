<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();
            $table->string('first_name', 120)->nullable()->after('name');
            $table->string('last_name', 120)->nullable()->after('first_name');
            $table->timestamp('phone_verified_at')->nullable()->after('phone');
            $table->timestamp('last_otp_verified_at')->nullable()->after('phone_verified_at');
            $table->string('pending_phone', 30)->nullable()->after('last_otp_verified_at');
        });

        Schema::create('auth_otp_challenges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('mobile', 16);
            $table->string('purpose', 24)->default('login');
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->char('code_hash', 64);
            $table->string('status', 24)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->char('request_ip_hash', 64)->nullable();
            $table->string('provider_reference', 180)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->index(['mobile', 'accepted_at'], 'auth_otp_mobile_accepted_idx');
            $table->index(['mobile', 'status', 'created_at'], 'auth_otp_mobile_status_idx');
            $table->index(['request_ip_hash', 'created_at'], 'auth_otp_ip_created_idx');
        });

        Schema::create('auth_otp_phone_locks', function (Blueprint $table) {
            $table->string('mobile', 16)->primary();
            $table->timestamps();
        });

        Schema::create('auth_mobile_claims', function (Blueprint $table) {
            $table->string('mobile', 16)->primary();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamp('verified_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_mobile_claims');
        Schema::dropIfExists('auth_otp_phone_locks');
        Schema::dropIfExists('auth_otp_challenges');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'last_name', 'phone_verified_at', 'last_otp_verified_at', 'pending_phone']);
        });
        // Keep nullable email/password: OTP-created accounts may use those columns as null.
    }
};
