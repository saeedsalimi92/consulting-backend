<?php
// Run after migrating the dedicated MySQL test database.
if (getenv('DB_DATABASE') !== 'consulting_booking_test_20261012') {
    fwrite(STDERR, "Refusing to run outside isolated booking test database\n");
    exit(1);
}
foreach (['APP_ENV'=>'testing','CACHE_STORE'=>'array','QUEUE_CONNECTION'=>'sync','MAIL_MAILER'=>'array'] as $key=>$value) {
    putenv($key.'='.$value); $_ENV[$key]=$value; $_SERVER[$key]=$value;
}
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.default')!=='mysql' || config('database.connections.mysql.database')!==getenv('DB_DATABASE')) {
    throw new RuntimeException('MySQL test database guard failed');
}
config(['cache.default'=>'array','queue.default'=>'sync','mail.default'=>'array','booking.payment_driver'=>'fake_bank']);

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

function check(bool $condition,string $name): void {
    if (!$condition) throw new RuntimeException("FAIL $name");
    echo "PASS $name\n";
}
function api(string $method,string $path,array $data=[],?string $token=null): array {
    global $app;
    static $n=0;
    $app['auth']->forgetGuards();
    $headers=['HTTP_ACCEPT'=>'application/json','CONTENT_TYPE'=>'application/json','REMOTE_ADDR'=>'10.22.0.'.(++$n)];
    if ($token) $headers['HTTP_AUTHORIZATION']='Bearer '.$token;
    $request=Request::create($path,$method,[],[],[],$headers,json_encode($data));
    $kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);
    $response=$kernel->handle($request);
    $result=[$response->getStatusCode(),json_decode($response->getContent(),true)];
    $kernel->terminate($request,$response);
    return $result;
}

$suffix=bin2hex(random_bytes(4));
$user=User::create(['name'=>'Concurrent test','email'=>"concurrent-$suffix@example.test",'password'=>'long-password-123']);
$token=$user->createToken('client',['client'])->plainTextToken;
$counselor=DB::table('counselors')->insertGetId(['name'=>'Concurrent counselor','slug'=>'concurrent-'.$suffix,
    'is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
$service=DB::table('booking_services')->insertGetId(['title'=>'Concurrent service','duration_minutes'=>60,
    'price_irr'=>1000000,'deposit_irr'=>300000,'is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
DB::table('counselor_booking_services')->insert(['counselor_id'=>$counselor,'booking_service_id'=>$service,
    'commission_type'=>'percentage','commission_value'=>25]);
$day=now('Asia/Tehran')->addDays(6)->startOfDay();
DB::table('counselor_working_hours')->insert(['counselor_id'=>$counselor,'weekday'=>$day->dayOfWeek,
    'starts_at'=>'09:00:00','ends_at'=>'12:00:00','is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
$payload=['counselor_id'=>$counselor,'service_id'=>$service,'starts_at'=>$day->copy()->addHours(9)->toIso8601String(),
    'mode'=>'online','payment_option'=>'deposit'];
DB::disconnect();
$start=microtime(true)+1.0;
$files=[]; $pids=[];
for ($i=0;$i<2;$i++) {
    $file='/tmp/booking_concurrency_'.$suffix.'_'.$i;
    $pid=pcntl_fork();
    if ($pid===-1) throw new RuntimeException('fork failed');
    if ($pid===0) {
        DB::purge('mysql');
        usleep(max(0,(int)(($start-microtime(true))*1000000)));
        try { [$status,$body]=api('POST','/api/booking/reservations',$payload,$token);
            file_put_contents($file,json_encode(['status'=>$status,'body'=>$body]));
        } catch (Throwable $e) { file_put_contents($file,json_encode(['error'=>get_class($e).': '.$e->getMessage()])); }
        exit;
    }
    $files[]=$file; $pids[]=$pid;
}
foreach ($pids as $pid) pcntl_waitpid($pid,$status);
$results=array_map(fn($file)=>json_decode(file_get_contents($file),true),$files);
foreach ($files as $file) unlink($file);
$statuses=array_column($results,'status'); sort($statuses);
check($statuses===[201,422], 'two simultaneous MySQL booking requests yield one reservation and one conflict');
check(DB::table('bookings')->where('counselor_id',$counselor)->count()===1,
    'concurrent requests create exactly one booking');

[$createdStatus,$created]=api('POST','/api/booking/reservations',[...$payload,
    'starts_at'=>$day->copy()->addHours(10)->toIso8601String(),'payment_option'=>'full'],$token);
check($createdStatus===201,'second MySQL slot prepared for payment race');
$bookingId=$created['data']['id'];
[$paymentStatus,$payment]=api('POST',"/api/booking/my-reservations/$bookingId/payments",[
    'amount_irr'=>1000000,'idempotency_key'=>'concurrent-payment-'.$suffix],$token);
check($paymentStatus===201,'payment attempt prepared for callback race');
$resultPath='/api/booking/fake-bank/payments/'.$payment['data']['payment_id'].'/result';
$resultPayload=['token'=>$payment['data']['checkout_token'],'result'=>'success'];
DB::disconnect();
$start=microtime(true)+1.0; $files=[]; $pids=[];
for($i=0;$i<2;$i++) {
    $file='/tmp/booking_payment_concurrency_'.$suffix.'_'.$i;
    $pid=pcntl_fork();
    if($pid===-1) throw new RuntimeException('payment fork failed');
    if($pid===0) {
        DB::purge('mysql');
        usleep(max(0,(int)(($start-microtime(true))*1000000)));
        try { [$status,$body]=api('POST',$resultPath,$resultPayload);
            file_put_contents($file,json_encode(['status'=>$status,'body'=>$body])); }
        catch(Throwable $e) { file_put_contents($file,json_encode(['error'=>get_class($e).': '.$e->getMessage()])); }
        exit;
    }
    $files[]=$file; $pids[]=$pid;
}
foreach($pids as $pid) pcntl_waitpid($pid,$status);
$results=array_map(fn($file)=>json_decode(file_get_contents($file),true),$files);
foreach($files as $file) unlink($file);
DB::purge('mysql');
check(count($results)===2 && array_sum(array_column($results,'status'))===400,
    'concurrent fake-bank success callbacks both return idempotent success');
check(DB::table('booking_payments')->where('id',$payment['data']['payment_id'])->where('status','verified')->count()===1 &&
    DB::table('booking_receipts')->where('payment_id',$payment['data']['payment_id'])->count()===1 &&
    DB::table('booking_ledger_entries')->where('event_key','payment:'.$payment['data']['payment_id'])->count()===3,
    'concurrent callbacks create one verified payment, receipt and balanced posting');
