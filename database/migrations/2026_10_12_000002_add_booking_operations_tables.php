<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('booking_payments',fn(Blueprint $t)=>$t->enum('provider',['zarinpal','snappay','in_person','mock'])->change());
        Schema::table('booking_payments',fn(Blueprint $t)=>$t->enum('payment_method',['cash','pos'])->nullable());
        Schema::table('bookings',function(Blueprint $t) {
            $t->unsignedBigInteger('commission_total_irr')->nullable();
            $t->unsignedSmallInteger('duration_minutes')->nullable();
        });
        Schema::table('booking_refunds',fn(Blueprint $t)=>$t->string('transfer_reference',255)->nullable()->unique());
        Schema::create('password_reset_tokens',function(Blueprint $t) {
            $t->string('email')->primary(); $t->string('token'); $t->timestamp('created_at')->nullable();
        });
        Schema::create('booking_settlements',function(Blueprint $t) {
            $t->id(); $t->foreignId('counselor_id')->constrained('counselors')->restrictOnDelete();
            $t->unsignedBigInteger('amount_irr'); $t->string('reference',100)->unique();
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->text('note')->nullable(); $t->timestamps();
        });
    }
    public function down(): void {
        // Retain the expanded enum to preserve any mock payment history.
        Schema::dropIfExists('booking_settlements');
        Schema::dropIfExists('password_reset_tokens');
        Schema::table('bookings',fn(Blueprint $t)=>$t->dropColumn(['commission_total_irr','duration_minutes']));
        Schema::table('booking_refunds',fn(Blueprint $t)=>$t->dropColumn('transfer_reference'));
        Schema::table('booking_payments',fn(Blueprint $t)=>$t->dropColumn('payment_method'));
    }
};
