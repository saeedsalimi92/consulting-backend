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
[$code,$registered]=api('POST','/api/client/register',['name'=>'Regression client','email'=>"reg-$suffix@example.test",
    'phone'=>'09'.substr(hexdec($suffix),0,9),'password'=>'long-password-123','password_confirmation'=>'long-password-123']);
check($code===201,'registration');
$clientToken=$registered['token'];
$clientId=$registered['data']['id'];
[$code]=api('PATCH','/api/client/me',['name'=>'Updated client','address'=>'Test address'],$clientToken);
check($code===200 && DB::table('users')->find($clientId)->name==='Updated client','client profile update');
[$code]=api('GET','/api/client/me',[]);
check($code===401,'anonymous profile denied');
$staff=User::create(['name'=>'Regression staff','email'=>"staff-$suffix@example.test",'password'=>'long-password-123',
    'admin_permissions'=>['appointments.manage']]);
$staffToken=$staff->createToken('admin-panel',['admin-panel'])->plainTextToken;
[$code]=api('GET','/api/admin/booking/services',[],$clientToken);
check($code===403,'client token denied admin API');
[$code]=api('GET','/api/client/me',[],$staffToken);
check($code===403,'staff token denied client API');

$counselor=DB::table('counselors')->insertGetId(['name'=>'Regression counselor','slug'=>'reg-'.$suffix,
    'is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
[$code,$service]=api('POST','/api/admin/booking/services',['title'=>'Regression session','duration_minutes'=>60,
    'price_irr'=>1000000,'deposit_irr'=>300000],$staffToken);
check($code===201,'service creation');
$serviceId=$service['data']['id'];
[$code]=api('GET',"/api/booking/availability?counselor_id=$counselor&service_id=$serviceId&date=".now('Asia/Tehran')->addDays(4)->format('Y-m-d'));
check($code===422,'unassigned service unavailable');
[$code]=api('PUT',"/api/admin/booking/counselors/$counselor/services",['service_id'=>$serviceId,
    'commission_type'=>'percentage','commission_value'=>'25.00'],$staffToken);
check($code===200,'service assignment');
$day=now('Asia/Tehran')->addDays(4)->startOfDay();
[$code,$shift]=api('POST',"/api/admin/booking/counselors/$counselor/shifts",['weekday'=>$day->dayOfWeek,
    'starts_at'=>'09:00','ends_at'=>'12:00'],$staffToken);
check($code===201,'working hours creation');
[$code]=api('POST',"/api/admin/booking/counselors/$counselor/shifts",['weekday'=>$day->dayOfWeek,
    'starts_at'=>'10:00','ends_at'=>'11:00'],$staffToken);
check($code===422,'overlapping working hours rejected');
$start=$day->copy()->addHours(9)->toIso8601String();
$offStart=$day->copy()->addHours(10)->toIso8601String();
$offEnd=$day->copy()->addHours(11)->toIso8601String();
[$code,$off]=api('POST',"/api/admin/booking/counselors/$counselor/time-off",[
    'starts_at'=>$offStart,'ends_at'=>$offEnd],$staffToken);
check($code===201,'time off creation');
[$code,$availability]=api('GET',"/api/booking/availability?counselor_id=$counselor&service_id=$serviceId&date=".$day->format('Y-m-d'));
check($code===200 && $availability['slots'][0]['available'] && !$availability['slots'][1]['available'],
    'availability respects working hours and time off');
[$code]=api('DELETE','/api/admin/booking/time-off/'.$off['data']['id'],[],$staffToken);
check($code===204,'time off deletion');

$base=['counselor_id'=>$counselor,'service_id'=>$serviceId,'starts_at'=>$start,'mode'=>'online','payment_option'=>'full'];
[$code,$created]=api('POST','/api/booking/reservations',$base,$clientToken);
check($code===201,'reservation after time off removal');
$booking=$created['data']['id'];
[$code]=api('POST',"/api/admin/booking/counselors/$counselor/time-off",[
    'starts_at'=>$start,'ends_at'=>$day->copy()->addHours(10)->toIso8601String()],$staffToken);
check($code===422,'time off cannot cover active reservation');
[$code,$note]=api('POST',"/api/admin/booking/reservations/$booking/notes",[
    'body'=>'Private test note','visible_to_client'=>false],$staffToken);
check($code===403,'reception cannot write clinical notes');
$notes=User::create(['name'=>'Notes manager','email'=>"notes-$suffix@example.test",'password'=>'long-password-123',
    'admin_permissions'=>['notes.manage']]);
$notesToken=$notes->createToken('admin-panel',['admin-panel'])->plainTextToken;
api('POST',"/api/admin/booking/reservations/$booking/notes",['body'=>'Private test note','visible_to_client'=>false],$notesToken);
api('POST',"/api/admin/booking/reservations/$booking/notes",['body'=>'Visible test note','visible_to_client'=>true],$notesToken);
[$code,$visible]=api('GET',"/api/booking/my-reservations/$booking/notes",[],$clientToken);
check($code===200 && count($visible['data'])===1 && $visible['data'][0]['body']==='Visible test note',
    'client sees only visible notes');
[$code,$payment]=api('POST',"/api/booking/my-reservations/$booking/payments",[
    'amount_irr'=>1000000,'idempotency_key'=>'reg-payment-'.$suffix],$clientToken);
check($code===201,'full payment initiated');
[$code]=api('POST','/api/booking/fake-bank/payments/'.$payment['data']['payment_id'].'/result',[
    'token'=>$payment['data']['checkout_token'],'result'=>'success']);
check($code===200,'full payment confirmed');
[$code,$replay]=api('POST',"/api/booking/my-reservations/$booking/payments",[
    'amount_irr'=>1000000,'idempotency_key'=>'reg-payment-'.$suffix],$clientToken);
check($code===200 && $replay['data']['payment_id']===$payment['data']['payment_id'],
    'idempotent payment replay works after full balance');
[$code]=api('POST',"/api/admin/booking/reservations/$booking/payments/in-person",[
    'amount_irr'=>1000000,'payment_method'=>'cash','idempotency_key'=>'reg-cash-'.$suffix,'reference'=>'receipt-'.$suffix],$staffToken);
check($code===409,'cash payment cannot be recorded for online booking');
[$code,$report]=api('GET','/api/admin/booking/reconciliation',[],$staffToken);
check($code===403,'reception denied reconciliation');

[$code]=api('POST','/api/client/logout',[],$clientToken);
check($code===204,'logout');
[$code]=api('GET','/api/client/me',[],$clientToken);
check($code===401,'revoked client token denied');
