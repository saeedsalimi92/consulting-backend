<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('counselors', function (Blueprint $table) {
            $table->boolean('is_public')->default(true)->after('is_active')->index();
        });
    }

    public function down(): void
    {
        Schema::table('counselors', function (Blueprint $table) {
            $table->dropIndex(['is_public']);
            $table->dropColumn('is_public');
        });
    }
};
