<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
        });

        Schema::create('admin_roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->string('label', 120);
            $table->boolean('is_assignable')->default(true);
            $table->timestamps();
        });
        Schema::create('admin_role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('admin_roles')->cascadeOnDelete();
            $table->string('permission', 80);
            $table->unique(['role_id', 'permission']);
        });
        Schema::create('admin_user_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('admin_roles')->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'role_id']);
        });

        $permissions = [
            'reception' => ['appointments.manage'],
            'finance' => ['finance.view'],
            'clinical' => ['notes.manage'],
            'content' => ['content.manage'],
            'operations' => ['appointments.manage', 'dashboard.view'],
        ];
        foreach ($permissions as $name => $grants) {
            $id = DB::table('admin_roles')->insertGetId([
                'name' => $name, 'label' => ucfirst($name), 'is_assignable' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($grants as $permission) {
                DB::table('admin_role_permissions')->insert(['role_id' => $id, 'permission' => $permission]);
            }
        }

        Schema::create('admin_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('permission', 80)->default('dashboard.view');
            $table->string('event', 100);
            $table->string('title', 180);
            $table->text('body')->nullable();
            $table->string('resource_type', 80)->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['permission', 'created_at']);
            $table->unique(['event', 'resource_type', 'resource_id', 'permission'], 'admin_notice_resource_unique');
        });
        Schema::create('admin_notification_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_notification_id')->constrained('admin_notifications')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('read_at');
            $table->unique(['admin_notification_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_notification_reads');
        Schema::dropIfExists('admin_notifications');
        Schema::dropIfExists('admin_user_roles');
        Schema::dropIfExists('admin_role_permissions');
        Schema::dropIfExists('admin_roles');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_active'));
    }
};
