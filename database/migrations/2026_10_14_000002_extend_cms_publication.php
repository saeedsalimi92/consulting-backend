<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('cms_entries', function (Blueprint $table) {
            $table->string('status', 20)->default('draft')->index();
            $table->timestamp('published_at')->nullable();
            $table->string('seo_title', 255)->nullable();
            $table->string('seo_description', 500)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
        });
        DB::table('cms_entries')->where('is_active', true)->update(['status' => 'published', 'published_at' => now()]);
        Schema::create('cms_private_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('disk', 40)->default('local');
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_private_media');
        Schema::table('cms_entries', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'published_at', 'seo_title', 'seo_description', 'sort_order']);
        });
    }
};
