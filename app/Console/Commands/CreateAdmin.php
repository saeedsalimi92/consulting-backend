<?php
namespace App\Console\Commands;
use App\Models\User;use Illuminate\Console\Command;
class CreateAdmin extends Command {protected $signature='admin:create {email}';protected $description='Create or promote an administrator (password prompted securely)';public function handle():int {$email=$this->argument('email');$name=$this->ask('Name');$password=$this->secret('Password (at least 12 chars)');if(strlen($password??'')<12){$this->error('Password too short');return self::FAILURE;}$u=User::firstOrNew(['email'=>$email]);$u->name=$name;$u->password=$password;$u->is_admin=true;$u->save();$this->info('Admin saved');return self::SUCCESS;}}
