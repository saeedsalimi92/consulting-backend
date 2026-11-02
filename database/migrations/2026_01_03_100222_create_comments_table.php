<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('guest_name')->nullable();
            $table->string('guest_email')->nullable();

            $table->text('body');

            $table->morphs('commentable');

            $table->foreignId('parent_id')->nullable()
                ->constrained('comments')
                ->nullOnDelete();

            $table->boolean('is_approved')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};

