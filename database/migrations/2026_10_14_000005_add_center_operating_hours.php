<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void {
        Schema::create('center_operating_hours',function(Blueprint $t){$t->id();$t->unsignedTinyInteger('weekday')->unique();$t->boolean('is_open')->default(true);$t->time('opens_at')->nullable();$t->time('closes_at')->nullable();$t->timestamps();});
        Schema::create('appointment_room_working_hours',function(Blueprint $t){$t->id();$t->foreignId('room_id')->constrained('appointment_rooms')->cascadeOnDelete();$t->unsignedTinyInteger('weekday');$t->time('opens_at');$t->time('closes_at');$t->timestamps();$t->index(['room_id','weekday']);});
    }
    public function down():void { Schema::dropIfExists('appointment_room_working_hours');Schema::dropIfExists('center_operating_hours'); }
};
