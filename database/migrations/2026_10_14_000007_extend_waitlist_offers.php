<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void { Schema::table('booking_waitlist',function(Blueprint $t){$t->timestamp('offer_expires_at')->nullable();$t->unsignedInteger('offer_count')->default(0);$t->index(['status','offer_expires_at']);}); }
    public function down():void { Schema::table('booking_waitlist',function(Blueprint $t){$t->dropIndex(['status','offer_expires_at']);$t->dropColumn(['offer_expires_at','offer_count']);}); }
};
