<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('booking_notification_templates', function (Blueprint $table) {
            $table->id();
            $table->string('event', 80);
            $table->string('channel', 20);
            $table->string('subject', 180)->nullable();
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['event', 'channel']);
        });
        Schema::table('booking_notification_logs', function (Blueprint $table) {
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('booking_notification_logs', fn (Blueprint $table) => $table->dropColumn(['attempts', 'last_attempt_at']));
        Schema::dropIfExists('booking_notification_templates');
    }
};
