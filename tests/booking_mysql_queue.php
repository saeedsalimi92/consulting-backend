<?php
if (getenv('DB_DATABASE') !== 'consulting_booking_test_20261012') {
    fwrite(STDERR, "Refusing to run outside isolated booking test database\n");
    exit(1);
}
foreach (['APP_ENV'=>'testing','QUEUE_CONNECTION'=>'database','BOOKING_SMS_DRIVER'=>'log','MAIL_MAILER'=>'array'] as $key=>$value) {
    putenv($key.'='.$value); $_ENV[$key]=$value; $_SERVER[$key]=$value;
}
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.default')!=='mysql' || config('database.connections.mysql.database')!==getenv('DB_DATABASE')) {
    throw new RuntimeException('MySQL test database guard failed');
}

use App\Jobs\SendBookingNotice;
use Illuminate\Support\Facades\DB;

if (($argv[1]??'')==='seed') {
    $booking=DB::table('bookings')->orderByDesc('id')->value('id');
    if (!$booking) throw new RuntimeException('Seed booking missing');
    $id=DB::table('booking_notification_logs')->insertGetId(['booking_id'=>$booking,'channel'=>'email',
        'recipient'=>'client','event'=>'worker-integration-'.bin2hex(random_bytes(4)),
        'status'=>'pending','created_at'=>now(),'updated_at'=>now()]);
    SendBookingNotice::dispatch($id);
    if (!DB::table('jobs')->exists()) throw new RuntimeException('Database queue did not receive job');
    file_put_contents('/tmp/booking_queue_notice_id',(string)$id);
    echo "PASS database queue accepted notification job\n";
} elseif (($argv[1]??'')==='check') {
    $id=(int)file_get_contents('/tmp/booking_queue_notice_id');
    if (DB::table('booking_notification_logs')->find($id)?->status!=='sent') {
        throw new RuntimeException('Queue worker did not send notice');
    }
    echo "PASS queue worker processed notification on isolated database\n";
} else {
    throw new RuntimeException('Use seed or check');
}
