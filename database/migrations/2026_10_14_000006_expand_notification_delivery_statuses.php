<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void { Schema::table('booking_notification_logs',fn(Blueprint $t)=>$t->string('status',30)->default('pending')->change()); }
    public function down():void { /* Preserve delivery states for audit history. */ }
};
