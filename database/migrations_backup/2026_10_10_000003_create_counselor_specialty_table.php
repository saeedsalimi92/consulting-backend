<?php
use Illuminate\Database\Migrations\Migration;use Illuminate\Database\Schema\Blueprint;use Illuminate\Support\Facades\Schema;
return new class extends Migration {public function up():void{Schema::create('counselor_specialty',function(Blueprint $t){$t->foreignId('counselor_id')->constrained()->cascadeOnDelete();$t->foreignId('specialty_id')->constrained()->cascadeOnDelete();$t->primary(['counselor_id','specialty_id']);});}public function down():void{Schema::dropIfExists('counselor_specialty');}};
