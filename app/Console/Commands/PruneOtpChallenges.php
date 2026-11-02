<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneOtpChallenges extends Command
{
    protected $signature = 'auth:prune-otp-challenges';
    protected $description = 'Delete OTP challenge records older than the configured short retention window.';

    public function handle(): int
    {
        $deleted=DB::table('auth_otp_challenges')->where('created_at','<',now()->subDay())->delete();
        $this->info("Pruned {$deleted} OTP challenge records.");
        return self::SUCCESS;
    }
}
