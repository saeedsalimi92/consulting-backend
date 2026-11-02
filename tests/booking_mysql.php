<?php
// MySQL integration checks. Run only against the dedicated disposable database.
$database = getenv('DB_DATABASE');
if ($database !== 'consulting_booking_test_20261012') {
    fwrite(STDERR, "Refusing to run outside isolated booking test database\n");
    exit(1);
}
foreach (['APP_ENV'=>'testing','CACHE_STORE'=>'array','QUEUE_CONNECTION'=>'sync','MAIL_MAILER'=>'array',
    'BOOKING_PAYMENT_DRIVER'=>'fake_bank'] as $key=>$value) {
    putenv($key.'='.$value); $_ENV[$key]=$value; $_SERVER[$key]=$value;
}
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== $database) {
    throw new RuntimeException('MySQL test database guard failed');
}
config(['cache.default'=>'array','queue.default'=>'sync','mail.default'=>'array','booking.payment_driver'=>'fake_bank']);

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

function check(bool $ok,string $message): void { if(!$ok) { fwrite(STDERR,"FAIL $message\n"); exit(1); } echo "PASS $message\n"; }
function api(string $method,string $path,array $data=[],?string $token=null): array {
    global $app;
    static $requestNumber=0;
    $app['auth']->forgetGuards();
    $headers=['HTTP_ACCEPT'=>'application/json','CONTENT_TYPE'=>'application/json',
        'REMOTE_ADDR'=>'10.0.0.'.(++$requestNumber)];
    if($token) $headers['HTTP_AUTHORIZATION']='Bearer '.$token;
    $request=Request::create($path,$method,[],[],[],$headers,json_encode($data));
    $kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);
    $response=$kernel->handle($request);
    $result=[$response->getStatusCode(),json_decode($response->getContent(),true)];
    $kernel->terminate($request,$response);
    return $result;
}

$suffix=bin2hex(random_bytes(4));
$phoneBase='09'.str_pad((string)(hexdec($suffix)%100000000),8,'0',STR_PAD_LEFT);
$client=User::create(['name'=>'Client','email'=>'client-'.$suffix.'@example.test','phone'=>$phoneBase.'1','password'=>'long-password-123']);
$other=User::create(['name'=>'Other','email'=>'other-'.$suffix.'@example.test','phone'=>$phoneBase.'2','password'=>'long-password-123']);
$token=$client->createToken('client',['client'])->plainTextToken;
$otherToken=$other->createToken('client',['client'])->plainTextToken;
$counselor=DB::table('counselors')->insertGetId(['name'=>'Counselor','slug'=>'smoke-counselor-'.$suffix,'is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
$service=DB::table('booking_services')->insertGetId(['title'=>'Session','duration_minutes'=>60,'price_irr'=>1000000,
    'deposit_irr'=>300000,'is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
DB::table('counselor_booking_services')->insert(['counselor_id'=>$counselor,'booking_service_id'=>$service,
    'commission_type'=>'percentage','commission_value'=>25]);
$day=now('Asia/Tehran')->addDays(2)->startOfDay();
DB::table('counselor_working_hours')->insert(['counselor_id'=>$counselor,'weekday'=>$day->dayOfWeek,
    'starts_at'=>'09:00:00','ends_at'=>'12:00:00','is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
$base=['counselor_id'=>$counselor,'service_id'=>$service,'mode'=>'online','payment_option'=>'deposit'];
$start=$day->copy()->addHours(9)->toIso8601String();
$misaligned=$day->copy()->addHours(9)->addMinutes(30)->toIso8601String();
[$code]=api('POST','/api/booking/reservations',[...$base,'starts_at'=>$misaligned],$token);
check($code===422,'misaligned slot rejected');
[$code,$created]=api('POST','/api/booking/reservations',[...$base,'starts_at'=>$start],$token);
check($code===201,'aligned slot reserved');
$booking=$created['data']['id'];
[$code]=api('POST','/api/booking/reservations',[...$base,'starts_at'=>$start],$otherToken);
check($code===422,'overlapping reservation rejected');
DB::table('bookings')->where('id',$booking)->update(['hold_expires_at'=>now()->utc()->subMinute()->toDateTimeString()]);
[$code,$availability]=api('GET','/api/booking/availability?counselor_id='.$counselor.'&service_id='.$service.'&date='.$day->format('Y-m-d'));
check($code===200 && $availability['slots'][0]['available']===true,'expired hold releases availability');
[$code,$created]=api('POST','/api/booking/reservations',[...$base,'starts_at'=>$start],$otherToken);
check($code===201,'slot can be reserved after hold expires');
$booking2=$created['data']['id'];
DB::table('booking_notes')->insert(['booking_id'=>$booking2,'author_id'=>$client->id,'body'=>'Private note',
    'visible_to_client'=>false,'created_at'=>now(),'updated_at'=>now()]);
[$code,$notes]=api('GET','/api/booking/my-reservations/'.$booking2.'/notes',[],$otherToken);
check($code===200 && count($notes['data'])===0,'private notes remain hidden');
[$code]=api('GET','/api/booking/my-reservations/'.$booking2,[],$token);
check($code===404,'other client cannot read booking');
[$code,$payment]=api('POST','/api/booking/my-reservations/'.$booking2.'/payments',
    ['amount_irr'=>300000,'idempotency_key'=>'smoke-pay-1-'.$suffix],$otherToken);
check($code===201,'fake-bank payment initiated');
[$code,$samePayment]=api('POST','/api/booking/my-reservations/'.$booking2.'/payments',
    ['amount_irr'=>300000,'idempotency_key'=>'smoke-pay-1-'.$suffix],$otherToken);
check($code===200 && $samePayment['data']['payment_id']===$payment['data']['payment_id'],'payment initiation is idempotent');
[$code,$confirmed]=api('POST','/api/booking/fake-bank/payments/'.$payment['data']['payment_id'].'/result',[
    'token'=>$payment['data']['checkout_token'],'result'=>'success']);
check($code===200 && $confirmed['data']['booking_status']==='confirmed','fake-bank result verifies and confirms deposit');
[$code]=api('POST','/api/booking/fake-bank/payments/'.$payment['data']['payment_id'].'/result',[
    'token'=>$payment['data']['checkout_token'],'result'=>'success']);
check($code===200 && DB::table('booking_ledger_entries')->where('event_key','payment:'.$payment['data']['payment_id'])->count()===3,
    'duplicate callback does not duplicate ledger');
check((int)DB::table('bookings')->find($booking2)->paid_irr===300000,'payment counted once');
[$code,$history]=api('GET','/api/booking/my-reservations/'.$booking2.'/payments',[],$otherToken);
check($code===200 && count($history['data'])===1,'client sees owned payment history');
[$code]=api('GET','/api/booking/my-reservations/'.$booking2.'/payments',[],$token);
check($code===404,'other client cannot see payment history');
[$code,$full]=api('POST','/api/booking/my-reservations/'.$booking2.'/payments',
    ['amount_irr'=>700000,'idempotency_key'=>'smoke-pay-2-'.$suffix],$otherToken);
check($code===201,'remaining balance payment initiated');
[$code]=api('POST','/api/booking/fake-bank/payments/'.$full['data']['payment_id'].'/result',[
    'token'=>$full['data']['checkout_token'],'result'=>'success']);
check($code===200 && (int)DB::table('bookings')->find($booking2)->paid_irr===1000000,'remaining balance paid');
$credits=(int)DB::table('booking_ledger_entries')->where('booking_id',$booking2)
    ->where('account','counselor_payable')->where('direction','credit')->sum('amount_irr');
check($credits===250000,'commission accrues exactly once across deposit and balance');
[$code,$refund]=api('POST','/api/booking/my-reservations/'.$booking2.'/cancellation-request',['reason'=>'Schedule changed'],$otherToken);
check($code===201 && $refund['data']['penalty_irr']===100000 && $refund['data']['refund_amount_irr']===900000,
    'configurable cancellation penalty uses actual paid amount');
[$code]=api('POST','/api/booking/my-reservations/'.$booking2.'/cancellation-request',[],$otherToken);
check($code===409,'duplicate cancellation request rejected');
$reception=User::create(['name'=>'Reception','email'=>'reception-'.$suffix.'@example.test','phone'=>$phoneBase.'3',
    'password'=>'long-password-123','admin_permissions'=>['appointments.manage']]);
$adminToken=$reception->createToken('admin-panel',['admin-panel'])->plainTextToken;
[$code]=api('GET','/api/admin/booking/reports',[],$adminToken);
check($code===403,'reception cannot access financial reports');
[$code]=api('GET','/api/admin/booking/reservations/'.$booking2.'/notes',[],$adminToken);
check($code===403,'reception cannot access clinical notes');
[$code]=api('GET','/api/admin/booking/reservations',[],$adminToken);
check($code===200,'reception can manage bookings');
[$code]=api('POST','/api/admin/booking/reservations/'.$booking2.'/payments/in-person',
    ['amount_irr'=>1,'payment_method'=>'cash','idempotency_key'=>'blocked-after-cancel-'.$suffix,'reference'=>'cash-1-'.$suffix],$adminToken);
check($code===409 && !DB::table('booking_payments')->where('idempotency_key','blocked-after-cancel-'.$suffix)->exists(),
    'payment blocked during cancellation review without orphan attempt');
[$code,$registered]=api('POST','/api/client/register',['name'=>'New Client','email'=>'new-'.$suffix.'@example.test',
    'phone'=>$phoneBase.'4','password'=>'long-password-123','password_confirmation'=>'long-password-123']);
check($code===201 && isset($registered['token']),'client registration issues scoped token');
[$code]=api('POST','/api/client/login',['email'=>'new-'.$suffix.'@example.test','password'=>'wrong-password']);
check($code===422,'invalid client password rejected');
[$code,$loggedIn]=api('POST','/api/client/login',['email'=>'new-'.$suffix.'@example.test','password'=>'long-password-123']);
check($code===200 && isset($loggedIn['token']),'client login succeeds');
$newClient=User::where('email','new-'.$suffix.'@example.test')->first();
config(['booking.client_reset_url'=>'https://public.example.test/reset-password']);
Illuminate\Support\Facades\Notification::fake();
[$code]=api('POST','/api/client/password/forgot',['email'=>'new-'.$suffix.'@example.test']);
check($code===200,'password reset request accepted');
$sent=Illuminate\Support\Facades\Notification::sent($newClient,Illuminate\Auth\Notifications\ResetPassword::class);
check(count($sent)===1,'reset notification generated');
[$code]=api('POST','/api/client/password/reset',['email'=>'new-'.$suffix.'@example.test','token'=>$sent[0]->token,
    'password'=>'new-long-password-123','password_confirmation'=>'new-long-password-123']);
check($code===200,'password reset completes');
[$code]=api('GET','/api/client/me',[],$loggedIn['token']);
check($code===401,'password reset revokes existing token');
[$code]=api('GET','/api/client/me',[],$adminToken);
check($code===403,'admin token cannot access client profile');
$admin=User::create(['name'=>'Admin','email'=>'admin-'.$suffix.'@example.test','phone'=>$phoneBase.'5',
    'password'=>'long-password-123','is_admin'=>true]);
$financeToken=$admin->createToken('admin-panel',['admin-panel'])->plainTextToken;
[$code,$report]=api('GET','/api/admin/booking/reports?counselor_id='.$counselor.'&provider=fake_bank&group=day',[],$financeToken);
check($code===200 && $report['data']['collected_irr']===1000000,'financial report filters verified payments');
[$code,$reconciliation]=api('GET','/api/admin/booking/reconciliation?counselor_id='.$counselor,[],$financeToken);
check($code===200 && count($reconciliation['data']['payment_mismatches'])===0 &&
    count($reconciliation['data']['unbalanced_ledger_events'])===0,'payment and ledger reconciliation is clean');
[$code,$reviewed]=api('PATCH','/api/admin/booking/refunds/'.$refund['data']['id'],['decision'=>'approved'],$financeToken);
check($code===200 && $reviewed['data']['status']==='approved','finance admin approves cancellation');
[$code,$transferred]=api('POST','/api/admin/booking/refunds/'.$refund['data']['id'].'/record-transfer',
    ['reference'=>'manual-refund-smoke-'.$suffix],$financeToken);
check($code===200 && $transferred['data']['status']==='refunded','external refund transfer recorded once');
[$code]=api('POST','/api/admin/booking/refunds/'.$refund['data']['id'].'/record-transfer',
    ['reference'=>'manual-refund-smoke-2-'.$suffix],$financeToken);
check($code===409,'duplicate refund transfer rejected');
$refundEntries=DB::table('booking_ledger_entries')->where('event_key','refund:'.$refund['data']['id'])->get();
check((int)$refundEntries->where('direction','debit')->sum('amount_irr')===
    (int)$refundEntries->where('direction','credit')->sum('amount_irr'),'refund ledger balances');
[$code,$payable]=api('GET','/api/admin/booking/counselors/'.$counselor.'/payable',[],$financeToken);
check($code===200 && $payable['data']['payable_irr']===25000,'payable reflects refund reversal');
[$code,$settlement]=api('POST','/api/admin/booking/settlements',
    ['counselor_id'=>$counselor,'amount_irr'=>25000,'reference'=>'settlement-smoke-'.$suffix],$financeToken);
check($code===201,'counselor settlement recorded');
[$code,$payable]=api('GET','/api/admin/booking/counselors/'.$counselor.'/payable',[],$financeToken);
check($code===200 && $payable['data']['payable_irr']===0,'settlement clears payable balance');
$settlementEntries=DB::table('booking_ledger_entries')->where('event_key','like','settlement:'.$settlement['data']['id'].':%')->get();
check((int)$settlementEntries->where('direction','debit')->sum('amount_irr')===25000 &&
    (int)$settlementEntries->where('direction','credit')->sum('amount_irr')===25000,'settlement ledger balances');
[$code,$manual]=api('POST','/api/admin/booking/reservations',['user_id'=>$client->id,
    'counselor_id'=>$counselor,'service_id'=>$service,'starts_at'=>$day->copy()->addHours(10)->toIso8601String(),
    'mode'=>'in_person','payment_option'=>'full'],$financeToken);
check($code===201,'staff creates appointment on free slot');
[$code,$cash]=api('POST','/api/admin/booking/reservations/'.$manual['data']['id'].'/payments/in-person',
    ['amount_irr'=>1000000,'payment_method'=>'cash','idempotency_key'=>'cash-smoke-'.$suffix,'reference'=>'cash-receipt-smoke-'.$suffix],$financeToken);
check($code===201 && $cash['data']['status']==='verified' &&
    DB::table('bookings')->find($manual['data']['id'])->status==='confirmed','authorized cash payment confirms appointment');
$noticeId=DB::table('booking_notification_logs')->insertGetId(['booking_id'=>$booking2,'channel'=>'email',
    'recipient'=>'counselor','event'=>'retry-smoke-'.$suffix,'status'=>'pending','created_at'=>now(),'updated_at'=>now()]);
try { (new App\Jobs\SendBookingNotice($noticeId))->handle(); } catch(RuntimeException) {}
check(DB::table('booking_notification_logs')->find($noticeId)->status==='failed','notification failure is logged');
DB::table('booking_notification_logs')->where('id',$noticeId)->update(['recipient'=>'client']);
(new App\Jobs\SendBookingNotice($noticeId))->handle();
check(DB::table('booking_notification_logs')->find($noticeId)->status==='sent','failed notification can retry');
$reminderBooking=DB::table('bookings')->insertGetId(['user_id'=>$client->id,'created_by'=>$admin->id,
    'counselor_id'=>$counselor,'booking_service_id'=>$service,'mode'=>'in_person',
    'starts_at'=>$day->copy()->addHours(9)->utc()->toDateTimeString(),
    'ends_at'=>$day->copy()->addHours(10)->utc()->toDateTimeString(),'status'=>'confirmed',
    'payment_option'=>'full','total_irr'=>1000000,'required_irr'=>1000000,'paid_irr'=>1000000,
    'commission_total_irr'=>250000,'duration_minutes'=>60,'created_at'=>now(),'updated_at'=>now()]);
Carbon::setTestNow($day->copy()->addHours(8));
Illuminate\Support\Facades\Artisan::call('booking:maintain');
check(DB::table('bookings')->find($booking)->status==='expired','maintenance expires stale hold');
check(DB::table('booking_notification_logs')->where('booking_id',$reminderBooking)->where('recipient','client')->count()===1 &&
    DB::table('booking_notification_logs')->where('booking_id',$reminderBooking)->where('recipient','counselor')->count()===1,
    'same-day reminders queued for client and counselor');
Carbon::setTestNow();
