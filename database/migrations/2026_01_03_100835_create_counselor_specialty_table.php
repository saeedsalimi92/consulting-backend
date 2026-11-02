<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('counselor_specialty', function (Blueprint $table) {
            $table->id();

            $table->foreignId('counselor_id')
                ->constrained('counselors')
                ->cascadeOnDelete();

            $table->foreignId('specialty_id')
                ->constrained('specialties')
                ->cascadeOnDelete();

            $table->unique(['counselor_id', 'specialty_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('counselor_specialty');
    }
};
