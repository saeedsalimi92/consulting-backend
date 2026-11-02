<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('counselors', fn (Blueprint $table) => $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete());
        Schema::table('booking_payments', function (Blueprint $table) {
            $table->string('provider', 30)->default('fake_bank')->change();
            $table->string('checkout_token_hash', 64)->nullable()->unique();
            $table->timestamp('checkout_expires_at')->nullable();
            $table->json('provider_metadata')->nullable();
        });
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('mode', 20)->default('online')->change();
            $table->string('status', 20)->default('held')->change();
            $table->string('session_mode', 20)->default('meet');
            $table->text('session_connection')->nullable();
            $table->text('session_instructions')->nullable();
            $table->timestamp('connection_released_at')->nullable();
            $table->timestamp('connection_release_notified_at')->nullable();
            $table->unsignedBigInteger('discount_irr')->default(0);
            $table->unsignedBigInteger('tax_rate_basis_points')->default(0);
            $table->unsignedBigInteger('tax_irr')->default(0);
            $table->unsignedBigInteger('base_price_irr')->nullable();
            $table->unsignedBigInteger('center_share_irr')->nullable();
        });
        Schema::table('booking_notes', function (Blueprint $table) {
            $table->boolean('is_private_session_note')->default(false);
        });
        Schema::table('booking_notification_logs', function (Blueprint $table) {
            $table->string('channel', 20)->default('in_app')->change();
            $table->string('recipient', 20)->default('client')->change();
            $table->string('status', 30)->default('pending')->change();
        });
        Schema::create('booking_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->restrictOnDelete();
            $table->foreignId('payment_id')->constrained('booking_payments')->restrictOnDelete()->unique();
            $table->string('receipt_number', 50)->unique();
            $table->unsignedBigInteger('amount_irr');
            $table->unsignedBigInteger('discount_irr')->default(0);
            $table->unsignedBigInteger('tax_irr')->default(0);
            $table->string('provider', 30);
            $table->string('provider_reference', 255);
            $table->timestamp('paid_at');
            $table->json('snapshot');
            $table->timestamps();
        });
        Schema::create('booking_in_app_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->cascadeOnDelete();
            $table->string('event_key', 150);
            $table->string('title', 180);
            $table->text('body');
            $table->json('payload')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'event_key']);
        });
        Schema::create('counselor_session_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('counselor_id')->constrained('counselors')->restrictOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->longText('body');
            $table->timestamps();
            $table->index(['counselor_id', 'booking_id']);
        });
        Schema::create('booking_gateway_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30)->unique();
            $table->boolean('enabled')->default(false);
            $table->string('mode', 20)->default('sandbox');
            $table->timestamps();
        });
        Schema::create('booking_service_tax_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_service_id')->constrained('booking_services')->cascadeOnDelete();
            $table->unsignedInteger('rate_basis_points')->default(0);
            $table->boolean('enabled')->default(false);
            $table->boolean('inclusive')->default(true);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();
            $table->index(['booking_service_id','effective_from','effective_to'],'booking_tax_effective');
        });
        Schema::create('booking_audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->string('action', 80);
            $table->json('details')->nullable();
            $table->timestamps();
            $table->index(['action', 'created_at']);
        });
        Schema::create('booking_waitlist', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('counselor_id')->constrained('counselors')->cascadeOnDelete();
            $table->foreignId('booking_service_id')->constrained('booking_services')->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->string('status',20)->default('active');
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id','counselor_id','booking_service_id','starts_at'],'booking_waitlist_unique');
            $table->index(['counselor_id','booking_service_id','starts_at','status'],'booking_waitlist_match');
        });
        Schema::create('booking_policy_settings', function (Blueprint $table) {
            $table->id(); $table->string('key',80)->unique(); $table->string('value',255); $table->timestamps();
        });
        DB::table('booking_policy_settings')->insert([
            ['key'=>'cancellation_penalty_percent','value'=>(string)env('BOOKING_CANCELLATION_PENALTY_PERCENT',10),'created_at'=>now(),'updated_at'=>now()],
            ['key'=>'cancellation_penalty_basis','value'=>env('BOOKING_CANCELLATION_PENALTY_BASIS','paid'),'created_at'=>now(),'updated_at'=>now()],
            ['key'=>'cancellation_cutoff_hours','value'=>(string)env('BOOKING_CANCELLATION_CUTOFF_HOURS',0),'created_at'=>now(),'updated_at'=>now()],
            ['key'=>'hold_minutes','value'=>(string)env('BOOKING_HOLD_MINUTES',20),'created_at'=>now(),'updated_at'=>now()],
            ['key'=>'connection_release_minutes','value'=>(string)env('BOOKING_CONNECTION_RELEASE_MINUTES',10),'created_at'=>now(),'updated_at'=>now()],
        ]);
        DB::table('booking_gateway_settings')->insert([
            ['provider' => 'fake_bank', 'enabled' => true, 'mode' => 'sandbox', 'created_at' => now(), 'updated_at' => now()],
            ['provider' => 'zarinpal', 'enabled' => false, 'mode' => 'sandbox', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_audit_events');
        Schema::dropIfExists('booking_waitlist');
        Schema::dropIfExists('booking_policy_settings');
        Schema::dropIfExists('booking_gateway_settings');
        Schema::dropIfExists('booking_service_tax_rules');
        Schema::dropIfExists('counselor_session_notes');
        Schema::dropIfExists('booking_in_app_notifications');
        Schema::dropIfExists('booking_receipts');
        Schema::table('booking_notes', fn (Blueprint $table) => $table->dropColumn('is_private_session_note'));
        Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn([
            'session_mode', 'session_connection', 'session_instructions', 'connection_released_at',
            'connection_release_notified_at', 'discount_irr', 'tax_rate_basis_points', 'tax_irr',
            'base_price_irr', 'center_share_irr',
        ]));
        Schema::table('booking_payments', fn (Blueprint $table) => $table->dropColumn([
            'checkout_token_hash', 'checkout_expires_at', 'provider_metadata',
        ]));
        Schema::table('counselors', fn (Blueprint $table) => $table->dropConstrainedForeignId('user_id'));
    }
};
