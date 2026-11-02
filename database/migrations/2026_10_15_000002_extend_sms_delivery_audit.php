<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('booking_notification_logs', function (Blueprint $table) {
            $table->string('provider',40)->nullable();
            $table->string('provider_reference',180)->nullable();
        });
    }
    public function down(): void
    {
        Schema::table('booking_notification_logs', fn (Blueprint $table) => $table->dropColumn(['provider','provider_reference']));
    }
};
