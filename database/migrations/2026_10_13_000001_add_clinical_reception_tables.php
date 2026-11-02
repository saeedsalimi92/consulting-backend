<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('appointment_rooms', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('location', 255)->nullable();
            $table->unsignedSmallInteger('capacity')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('room_id')->nullable()->after('counselor_id')->constrained('appointment_rooms')->nullOnDelete();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('session_started_at')->nullable();
            $table->timestamp('session_completed_at')->nullable();
            $table->index(['room_id', 'starts_at', 'ends_at', 'status'], 'booking_room_overlap_lookup');
        });
        Schema::table('booking_waitlist', function (Blueprint $table) {
            $table->foreignId('assigned_booking_id')->nullable()->constrained('bookings')->nullOnDelete();
        });

        Schema::create('client_clinical_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignId('primary_counselor_id')->nullable()->constrained('counselors')->nullOnDelete();
            $table->text('client_concerns')->nullable();
            $table->json('treatment_goals')->nullable();
            $table->text('progress_summary')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('intake_forms', function (Blueprint $table) {
            $table->id();
            $table->string('title', 180);
            $table->string('key', 100)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('intake_form_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intake_form_id')->constrained('intake_forms')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('schema');
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['intake_form_id', 'version']);
        });
        Schema::create('intake_form_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('intake_form_version_id')->constrained('intake_form_versions')->restrictOnDelete();
            $table->json('answers');
            $table->timestamp('submitted_at');
            $table->timestamps();
            $table->index(['client_id', 'submitted_at']);
        });
        Schema::create('client_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->string('consent_key', 100);
            $table->unsignedInteger('version')->default(1);
            $table->boolean('accepted');
            $table->timestamp('accepted_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
            $table->index(['client_id', 'consent_key', 'version']);
        });
        Schema::create('client_follow_up_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('counselor_id')->constrained('counselors')->restrictOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('title', 180);
            $table->text('details')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['counselor_id', 'completed_at', 'due_date']);
        });
        Schema::create('client_private_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('disk', 40)->default('local');
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps();
            $table->index(['client_id', 'created_at']);
        });
        Schema::table('counselor_session_notes', function (Blueprint $table) {
            $table->string('status', 20)->default('finalized');
            $table->json('outcomes')->nullable();
            $table->text('follow_up_recommendations')->nullable();
            $table->text('treatment_progress')->nullable();
            $table->timestamp('finalized_at')->nullable();
        });
        Schema::create('counselor_session_note_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('note_id')->constrained('counselor_session_notes')->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->json('snapshot');
            $table->timestamps();
            $table->index(['note_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('counselor_session_note_versions');
        Schema::table('counselor_session_notes', fn (Blueprint $table) => $table->dropColumn([
            'status', 'outcomes', 'follow_up_recommendations', 'treatment_progress', 'finalized_at',
        ]));
        foreach ([
            'client_private_attachments', 'client_follow_up_tasks', 'client_consents',
            'intake_form_answers', 'intake_form_versions', 'intake_forms', 'client_clinical_records',
        ] as $table) Schema::dropIfExists($table);
        Schema::table('booking_waitlist', fn (Blueprint $table) => $table->dropConstrainedForeignId('assigned_booking_id'));
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('booking_room_overlap_lookup');
            $table->dropConstrainedForeignId('room_id');
            $table->dropColumn(['checked_in_at', 'session_started_at', 'session_completed_at']);
        });
        Schema::dropIfExists('appointment_rooms');
    }
};
