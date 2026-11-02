<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { Schema::create('counselors',function(Blueprint $t){$t->id();$t->string('name');$t->string('slug')->unique();$t->string('avatar')->nullable();$t->text('bio')->nullable();$t->longText('about')->nullable();$t->string('degree')->nullable();$t->string('license_number')->nullable();$t->unsignedSmallInteger('years_of_experience')->nullable();$t->json('languages')->nullable();foreach(['instagram','telegram','whatsapp','website','linkedin'] as $s)$t->string($s)->nullable();$t->string('meta_title')->nullable();$t->string('meta_description',500)->nullable();$t->boolean('is_active')->default(true)->index();$t->timestamps();$t->softDeletes();}); }
 public function down(): void {Schema::dropIfExists('counselors');}
};
