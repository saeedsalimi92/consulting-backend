<?php
use Illuminate\Database\Migrations\Migration;use Illuminate\Database\Schema\Blueprint;use Illuminate\Support\Facades\Schema;
return new class extends Migration {public function up():void {
 Schema::create('cms_entries',function(Blueprint $t){$t->id();$t->string('type',40)->index();$t->string('title');$t->string('slug');$t->longText('body')->nullable();$t->string('image')->nullable();$t->json('extra')->nullable();$t->boolean('is_active')->default(false);$t->timestamps();$t->unique(['type','slug']);});
 Schema::create('cms_settings',function(Blueprint $t){$t->id();$t->string('key')->unique();$t->longText('value')->nullable();$t->timestamps();});
 Schema::create('appointment_requests',function(Blueprint $t){$t->id();$t->foreignId('counselor_id')->nullable()->constrained('counselors')->nullOnDelete();$t->string('name');$t->string('phone',30);$t->dateTime('preferred_at')->nullable();$t->text('notes')->nullable();$t->string('status',30)->default('new');$t->timestamps();});
 }public function down():void {Schema::dropIfExists('appointment_requests');Schema::dropIfExists('cms_settings');Schema::dropIfExists('cms_entries');}};
