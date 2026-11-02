<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('users', function (Blueprint $t) {
            $t->string('phone', 30)->nullable()->unique();
            $t->string('national_id', 20)->nullable();
            $t->date('birth_date')->nullable();
            $t->text('address')->nullable();
        });
        Schema::create('booking_services', function (Blueprint $t) {
            $t->id(); $t->string('title'); $t->unsignedSmallInteger('duration_minutes');
            $t->unsignedBigInteger('price_irr'); $t->unsignedBigInteger('deposit_irr')->default(0);
            $t->boolean('is_active')->default(true); $t->timestamps();
        });
        Schema::create('counselor_booking_services', function (Blueprint $t) {
            $t->id(); $t->foreignId('counselor_id')->constrained('counselors')->cascadeOnDelete();
            $t->foreignId('booking_service_id')->constrained('booking_services')->cascadeOnDelete();
            $t->unsignedBigInteger('price_irr')->nullable();
            $t->enum('commission_type',['percentage','fixed'])->default('percentage');
            $t->decimal('commission_value',12,2)->default(0);
            $t->unique(['counselor_id','booking_service_id'],'counselor_service_unique');
        });
        Schema::create('counselor_working_hours', function (Blueprint $t) {
            $t->id(); $t->foreignId('counselor_id')->constrained('counselors')->cascadeOnDelete();
            $t->unsignedTinyInteger('weekday'); // 0=Sunday through 6=Saturday, in Asia/Tehran
            $t->time('starts_at'); $t->time('ends_at'); $t->boolean('is_active')->default(true);
            $t->timestamps(); $t->index(['counselor_id','weekday']);
        });
        Schema::create('counselor_time_off', function (Blueprint $t) {
            $t->id(); $t->foreignId('counselor_id')->constrained('counselors')->cascadeOnDelete();
            $t->dateTime('starts_at'); $t->dateTime('ends_at'); $t->string('reason')->nullable(); $t->timestamps();
        });
        Schema::create('bookings', function (Blueprint $t) {
            $t->id(); $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('counselor_id')->constrained('counselors')->restrictOnDelete();
            $t->foreignId('booking_service_id')->constrained('booking_services')->restrictOnDelete();
            $t->enum('mode',['online','in_person']);
            $t->dateTime('starts_at'); $t->dateTime('ends_at'); // stored in UTC
            $t->enum('status',['held','confirmed','completed','cancelled','expired'])->default('held');
            $t->enum('payment_option',['full','deposit'])->default('full');
            $t->unsignedBigInteger('total_irr'); $t->unsignedBigInteger('required_irr');
            $t->unsignedBigInteger('paid_irr')->default(0); $t->timestamp('hold_expires_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('cancellation_reason')->nullable(); $t->timestamps();
            $t->index(['counselor_id','starts_at','ends_at','status'],'booking_overlap_lookup');
            $t->index(['user_id','starts_at']);
        });
        Schema::create('booking_notes', function (Blueprint $t) {
            $t->id(); $t->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $t->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $t->longText('body'); $t->boolean('visible_to_client')->default(false); $t->timestamps();
        });
        Schema::create('booking_payments', function (Blueprint $t) {
            $t->id(); $t->foreignId('booking_id')->constrained('bookings')->restrictOnDelete();
            $t->enum('provider',['zarinpal','snappay','in_person']);
            $t->enum('status',['created','pending','verified','failed','refunded'])->default('created');
            $t->unsignedBigInteger('amount_irr'); $t->string('provider_authority')->nullable()->unique();
            $t->string('provider_reference')->nullable()->unique(); $t->string('idempotency_key',100)->unique();
            $t->json('provider_payload')->nullable(); $t->timestamp('verified_at')->nullable();
            $t->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete(); $t->timestamps();
        });
        Schema::create('booking_refunds', function (Blueprint $t) {
            $t->id(); $t->foreignId('booking_id')->constrained('bookings')->restrictOnDelete();
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->unsignedBigInteger('base_amount_irr'); $t->unsignedBigInteger('penalty_irr');
            $t->unsignedBigInteger('refund_amount_irr');
            $t->enum('status',['requested','approved','rejected','processing','refunded','failed'])->default('requested');
            $t->text('reason')->nullable(); $t->timestamps();
        });
        Schema::create('booking_ledger_entries', function (Blueprint $t) {
            $t->id(); $t->foreignId('booking_id')->constrained('bookings')->restrictOnDelete();
            $t->foreignId('payment_id')->nullable()->constrained('booking_payments')->restrictOnDelete();
            $t->string('account',60); $t->enum('direction',['debit','credit']);
            $t->unsignedBigInteger('amount_irr'); $t->string('event_key',150);
            $t->timestamps(); $t->unique(['event_key','account','direction'],'booking_ledger_event_unique');
        });
        Schema::create('booking_notification_logs', function (Blueprint $t) {
            $t->id(); $t->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $t->enum('channel',['sms','email']); $t->enum('recipient',['client','counselor']);
            $t->string('event',60); $t->enum('status',['pending','sent','failed'])->default('pending');
            $t->timestamp('sent_at')->nullable(); $t->text('error')->nullable(); $t->timestamps();
            $t->unique(['booking_id','channel','recipient','event'],'booking_notice_unique');
        });
    }
    public function down(): void {
        foreach (['booking_notification_logs','booking_ledger_entries','booking_refunds','booking_payments','booking_notes','bookings','counselor_time_off','counselor_working_hours','counselor_booking_services','booking_services'] as $table) Schema::dropIfExists($table);
        Schema::table('users',fn(Blueprint $t)=>$t->dropColumn(['phone','national_id','birth_date','address']));
    }
};
