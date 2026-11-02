<?php

namespace Database\Seeders;

use App\Models\CmsEntry;
use App\Models\User;
use App\Modules\Counselor\Models\Counselor;
use App\Modules\Counselor\Models\Specialty;
use App\Services\BookingMoney;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** Idempotent, additive fixtures. All identifiers are namespaced with demo-. */
class DemoSeeder extends Seeder
{
    private const PREFIX = 'demo-';
    private ?int $adminId = null;

    public function run(): void
    {
        DB::transaction(function (): void {
            $this->seedSpecialties();
            $counselors = $this->seedCounselors();
            $clients = $this->seedClients();
            $services = $this->seedServices($counselors);
            $this->seedSchedules($counselors);
            $this->seedBookings($counselors, $clients, $services);
            $this->seedCms();
        }, 3);
    }

    private function seedSpecialties(): void
    {
        foreach ([
            'مشاوره فردی'=>'individual','زوج‌درمانی'=>'couples','مشاوره خانواده'=>'family',
            'روان‌شناسی کودک'=>'child','مشاوره نوجوان'=>'adolescent','مدیریت اضطراب'=>'anxiety',
            'مدیریت افسردگی'=>'depression','مشاوره شغلی'=>'career','مشاوره پیش از ازدواج'=>'premarital',
            'مدیریت استرس'=>'stress','رشد و توسعه فردی'=>'personal-growth','مهارت‌های ارتباطی'=>'communication',
            'سوگ و فقدان'=>'grief','مهارت‌های فرزندپروری'=>'parenting',
        ] as $title=>$slug) {
            Specialty::updateOrCreate(['slug'=>self::PREFIX.$slug], ['title'=>$title,'is_active'=>true]);
        }
    }

    private function seedCounselors(): array
    {
        $names=['نرگس رستگار','آرمان نیک‌پی','الهام فرهمند','کیانوش بهرامی','سارا مهرآیین','پویان شریفی','مینا دادخواه','آرزو یوسفی','بهنام رفیعی','نگین امینی','فرزاد نیکنام','مهسا کیانی','شایان صادقی','لیلا کاویانی','پارسا زمانی','ترانه محسنی','سامان آذرخش','حدیث شمس','امیرحسین فلاح','روژان کریمی','یاسمن بهشتی','کوروش میرزایی','آوا نادری','مهران توسلی'];
        $degrees=['کارشناسی ارشد روان‌شناسی بالینی','کارشناسی ارشد مشاوره خانواده','کارشناسی ارشد روان‌شناسی عمومی','دکتری روان‌شناسی (عنوان نمایشی)','کارشناسی ارشد مشاوره و راهنمایی'];
        $specialties=Specialty::where('slug','like',self::PREFIX.'%')->orderBy('id')->get()->values();
        $out=[];
        foreach ($names as $i=>$name) {
            $slug=self::PREFIX.'counselor-'.str_pad((string)($i+1),2,'0',STR_PAD_LEFT);
            $c=Counselor::withTrashed()->updateOrCreate(['slug'=>$slug],[
                'name'=>$name,'avatar'=>'/images/demo/counselor-placeholder.svg',
                'bio'=>'مشاور داستانی برای نمایش امکانات مرکز؛ این پروفایل و سوابق آن نمونه‌ای آموزشی هستند.',
                'about'=>'رویکردی همدلانه و مشارکتی برای شناخت نیازها و تمرین راهکارهای متناسب با شرایط هر فرد. اطلاعات تحصیلی این نمایه صرفاً محتوای ساختگیِ نمایشی است.',
                'degree'=>$degrees[$i%count($degrees)],'license_number'=>null,'years_of_experience'=>2+($i%17),
                'languages'=>json_encode(['فارسی','انگلیسی'],JSON_UNESCAPED_UNICODE),
                'is_active'=>$i%7!==6,'meta_title'=>$name.' | نمایه نمایشی','meta_description'=>'نمایه ساختگی برای محیط نمایشی مرکز مشاوره',
                'deleted_at'=>null,
            ]);
            $c->specialties()->syncWithoutDetaching([$specialties[$i%$specialties->count()]->id,$specialties[($i+3)%$specialties->count()]->id]);
            $out[]=$c;
        }
        return $out;
    }

    private function seedClients(): array
    {
        $first=['آرین','باران','پارمیس','دانیال','رها','سام','ستایش','شروین','غزل','مانی','نیایش','هلیا','یونا','کیانا','آترین','بهار','پرهام','ترنم','حامی','درسا','رایان','ساغر','سپهر','شیدا','طاها','فریبا','کاوه','لاله','ماهان','نیکا','آوا','بهراد','پگاه','ژینا','رامین','سحر','سینا','شکوفه','فرهاد','کتایون','مهرسا','نوید','هانیه','یاسر','آیدا','بامداد','پرستو','ثنا','جواد','دنیا'];
        $out=[];
        foreach ($first as $i=>$name) {
            $email=sprintf('client%02d@demo.example.test',$i+1);
            $u=User::firstOrCreate(['email'=>$email],['name'=>$name.' نمونه','password'=>Hash::make('Demo-Only-'.str_pad((string)($i+1),2,'0',STR_PAD_LEFT)),'is_admin'=>false,'admin_permissions'=>null,'phone'=>null,'national_id'=>null,'birth_date'=>null,'address'=>null]);
            $out[]=$u;
        }
        return $out;
    }

    private function seedServices(array $counselors): array
    {
        $definitions=[
            ['جلسه فردی حضوری',50,18000000,6000000,'in'],['جلسه فردی آنلاین',50,16000000,5000000,'in'],
            ['زوج‌درمانی حضوری',75,28000000,9000000,'couple'],['زوج‌درمانی آنلاین',75,25000000,8000000,'couple'],
            ['مشاوره خانواده',75,30000000,10000000,'family'],['مشاوره کودک با والد',50,22000000,7000000,'family'],
            ['مشاوره نوجوان',50,20000000,6500000,'in'],['راهنمایی شغلی',60,21000000,7000000,'in'],
            ['جلسه پیش از ازدواج',75,27000000,9000000,'couple'],['مدیریت استرس آنلاین',50,17000000,5000000,'in'],
        ];
        $rows=[];
        foreach ($definitions as $i=>[$title,$duration,$price,$deposit,$group]) {
            $id=DB::table('booking_services')->updateOrInsert(['title'=>self::PREFIX.$title],['duration_minutes'=>$duration,'price_irr'=>$price,'deposit_irr'=>$deposit,'is_active'=>true,'updated_at'=>now(),'created_at'=>now()]);
            $service=DB::table('booking_services')->where('title',self::PREFIX.$title)->first(); $rows[]=$service;
            foreach ($counselors as $j=>$c) {
                if (!$c->is_active || (($j+$i)%4===0)) continue;
                $eligible=match($group){'couple'=>$i===2||$i===3||$i===8,'family'=>$i===4||$i===5,default=>true};
                if (!$eligible) continue;
                $priceOverride=$price+(($j%3)*500000);
                DB::table('counselor_booking_services')->updateOrInsert(['counselor_id'=>$c->id,'booking_service_id'=>$service->id],['price_irr'=>$priceOverride,'commission_type'=>$j%2?'percentage':'fixed','commission_value'=>$j%2?'20.00':'3000000']);
            }
        }
        return $rows;
    }

    private function seedSchedules(array $counselors): void
    {
        foreach ($counselors as $i=>$c) {
            foreach ([1,3,5] as $weekday) {
                foreach ([['09:00:00','13:00:00'],['15:00:00','19:00:00']] as $shift=>$hours) {
                    DB::table('counselor_working_hours')->updateOrInsert(['counselor_id'=>$c->id,'weekday'=>$weekday,'starts_at'=>$hours[0],'ends_at'=>$hours[1]],['is_active'=>true,'updated_at'=>now(),'created_at'=>now()]);
                }
            }
            $start=Carbon::now('Asia/Tehran')->addDays(25+($i%15))->setTime(9,0)->utc();
            DB::table('counselor_time_off')->updateOrInsert(['counselor_id'=>$c->id,'starts_at'=>$start->toDateTimeString(),'ends_at'=>$start->copy()->addHours(3)->toDateTimeString()],['reason'=>'زمان عدم دسترسی نمونه','updated_at'=>now(),'created_at'=>now()]);
        }
    }

    private function seedBookings(array $counselors,array $clients,array $services): void
    {
        $admin=$this->demoAdmin();
        $clientIds=array_map(fn ($client)=>$client->id,$clients);
        $existing=DB::table('bookings')->whereIn('user_id',$clientIds)->count();
        if ($existing>=180) return;
        $now=Carbon::now('Asia/Tehran'); $made=0; $candidate=0;
        while ($made+$existing<180 && $candidate<1800) {
            $n=$candidate++; $c=$counselors[$n%count($counselors)];
            if (!$c->is_active) continue;
            $date=$now->copy()->subDays(75)->addDays((int)(($n*7)%166));
            $weekday=$date->dayOfWeek;
            if (!in_array($weekday,[1,3,5],true)) continue;
            $hour=($n%2)?9:15; $slot=($n%4); $start=$date->copy()->setTime($hour+intdiv($slot,2),($slot%2)*30);
            $svc=null;
            foreach ($services as $s) if (DB::table('counselor_booking_services')->where('counselor_id',$c->id)->where('booking_service_id',$s->id)->exists()) {$svc=$s;break;}
            if (!$svc) continue;
            $starts=$start->copy()->utc(); $ends=$starts->copy()->addMinutes($svc->duration_minutes);
            if (DB::table('bookings')->where('user_id',$clients[$n%count($clients)]->id)->where('counselor_id',$c->id)->where('booking_service_id',$svc->id)->where('starts_at',$starts->toDateTimeString())->exists()) continue;
            if (DB::table('counselor_time_off')->where('counselor_id',$c->id)->where('starts_at','<',$ends)->where('ends_at','>',$starts)->exists()) continue;
            if (DB::table('bookings')->where('counselor_id',$c->id)->where('starts_at','<',$ends)->where('ends_at','>',$starts)->whereIn('status',['held','confirmed','completed'])->exists()) continue;
            $key=self::PREFIX.'booking-'.str_pad((string)($n+1),4,'0',STR_PAD_LEFT);
            if (DB::table('bookings')->where('created_by',$admin)->where('cancellation_reason',$key)->exists()) continue;
            $assignment=DB::table('counselor_booking_services')->where('counselor_id',$c->id)->where('booking_service_id',$svc->id)->first();
            $price=(int)($assignment->price_irr??$svc->price_irr); $commission=BookingMoney::commission($price,$assignment->commission_type,(string)$assignment->commission_value);
            $past=$starts->lt(now()->utc()); $status=$past?($n%12===0?'cancelled':($n%9===0?'expired':'completed')):($n%5===0?'held':'confirmed');
            if ($status==='held' && $starts->diffInMinutes(now()->utc(),false)>-10) $status='expired';
            if ($status==='held' && $starts->gt(now()->utc()->addMinutes(10))) $status='confirmed';
            $option=$n%3===0?'deposit':'full'; $required=$option==='deposit'?min($price,(int)$svc->deposit_irr):$price;
            $paid=match($status){'completed'=>$price,'confirmed'=>$n%2?$price:$required,'cancelled'=>$required,default=>0};
            $bookingId=DB::table('bookings')->insertGetId(['user_id'=>$clients[$n%count($clients)]->id,'created_by'=>$admin,'counselor_id'=>$c->id,'booking_service_id'=>$svc->id,'mode'=>$n%2?'online':'in_person','starts_at'=>$starts->toDateTimeString(),'ends_at'=>$ends->toDateTimeString(),'status'=>$status,'payment_option'=>$option,'total_irr'=>$price,'required_irr'=>$required,'paid_irr'=>$paid,'hold_expires_at'=>$status==='held'?now()->utc()->addMinutes(10):null,'commission_total_irr'=>$commission,'duration_minutes'=>$svc->duration_minutes,'cancellation_reason'=>$status==='cancelled'?$key:null,'created_at'=>now(),'updated_at'=>now()]);
            if ($paid>0) $this->recordDemoPayment($bookingId,$paid,$commission,$status,$n,$admin);
            if ($status==='cancelled' && $paid>0 && $n%2===0) {
                $penalty=(int)intdiv($paid*10,100); $refund=$paid-$penalty; $refundStatus=$n%4===0?'requested':'refunded';
                DB::table('booking_refunds')->updateOrInsert(['booking_id'=>$bookingId,'reason'=>'درخواست بازپرداخت نمونه'],['requested_by'=>$clients[$n%count($clients)]->id,'approved_by'=>$refundStatus==='refunded'?$admin:null,'base_amount_irr'=>$paid,'penalty_irr'=>$penalty,'refund_amount_irr'=>$refund,'status'=>$refundStatus,'transfer_reference'=>$refundStatus==='refunded'?self::PREFIX.'refund-'.$bookingId:null,'created_at'=>now(),'updated_at'=>now()]);
                if ($refundStatus==='refunded') $this->recordDemoRefund($bookingId,$refund,$commission,$price);
            }
            if ($n%11===0) DB::table('booking_notes')->updateOrInsert(['booking_id'=>$bookingId,'body'=>'یادداشت نمایشی: هماهنگی جلسه طبق برنامه انجام شد.'],['author_id'=>$admin,'visible_to_client'=>true,'created_at'=>now(),'updated_at'=>now()]);
            if ($n%17===0) DB::table('booking_notes')->updateOrInsert(['booking_id'=>$bookingId,'body'=>'یادداشت داخلی نمایشی؛ اطلاعات بالینی ثبت نشود.'],['author_id'=>$admin,'visible_to_client'=>false,'created_at'=>now(),'updated_at'=>now()]);
            if ($n%13===0) DB::table('booking_notification_logs')->updateOrInsert(['booking_id'=>$bookingId,'channel'=>'email','recipient'=>'client','event'=>'demo_notice'],['status'=>'pending','sent_at'=>null,'error'=>'ثبت نمایشی؛ ارسال نشده','created_at'=>now(),'updated_at'=>now()]);
            $made++;
        }
    }

    private function recordDemoPayment(int $bookingId,int $amount,int $commission,string $bookingStatus,int $n,int $admin): void
    {
        $provider=$n%3===0?'in_person':'mock';
        $paymentId=DB::table('booking_payments')->updateOrInsert(['idempotency_key'=>self::PREFIX.'payment-'.$bookingId],['booking_id'=>$bookingId,'provider'=>$provider,'status'=>'verified','amount_irr'=>$amount,'provider_authority'=>null,'provider_reference'=>self::PREFIX.'reference-'.$bookingId,'provider_payload'=>json_encode(['demo'=>true,'verified_by_gateway'=>false]),'verified_at'=>now(),'recorded_by'=>$admin,'payment_method'=>$provider==='in_person'?($n%2?'cash':'pos'):null,'created_at'=>now(),'updated_at'=>now()]);
        $p=DB::table('booking_payments')->where('idempotency_key',self::PREFIX.'payment-'.$bookingId)->first();
        $share=min($commission,(int)intdiv($commission*$amount,max(1,(int)DB::table('bookings')->where('id',$bookingId)->value('total_irr'))));
        foreach ([['cash','debit',$amount],['counselor_payable','credit',$share],['center_revenue','credit',$amount-$share]] as [$account,$direction,$sum]) if ($sum>0) DB::table('booking_ledger_entries')->updateOrInsert(['event_key'=>self::PREFIX.'payment:'.$p->id,'account'=>$account,'direction'=>$direction],['booking_id'=>$bookingId,'payment_id'=>$p->id,'amount_irr'=>$sum,'created_at'=>now(),'updated_at'=>now()]);
    }

    private function recordDemoRefund(int $bookingId,int $refund,int $commission,int $total): void
    {
        $payment=DB::table('booking_payments')->where('booking_id',$bookingId)->where('status','verified')->first();
        if (!$payment || $refund<=0) return;
        $commissionReversal=min($commission,(int)intdiv($commission*$refund,max(1,$total)));
        $event=self::PREFIX.'refund:'.$bookingId;
        foreach ([['counselor_payable','debit',$commissionReversal],['center_revenue','debit',$refund-$commissionReversal],['cash','credit',$refund]] as [$account,$direction,$amount]) {
            if ($amount>0) DB::table('booking_ledger_entries')->updateOrInsert(['event_key'=>$event,'account'=>$account,'direction'=>$direction],['booking_id'=>$bookingId,'payment_id'=>$payment->id,'amount_irr'=>$amount,'created_at'=>now(),'updated_at'=>now()]);
        }
    }

    private function demoAdmin(): int
    {
        if ($this->adminId) return $this->adminId;
        $admin=User::where('is_admin',true)->orderBy('id')->first();
        if (!$admin) $admin=User::where('email','like','client%@demo.example.test')->orderBy('id')->first();
        return $this->adminId=$admin->id;
    }

    private function seedCms(): void
    {
        $entries=[
            ['pages','صفحه نخست','home','به مرکز مشاوره نمونه خوش آمدید؛ فضایی برای گفت‌وگوی امن و رشد فردی.','/images/demo/center-placeholder.svg'],
            ['pages','درباره ما','about','این محتوای نمونه برای نمایش ساختار معرفی مرکز نوشته شده و بیانگر یک مجموعه واقعی یا مجوز حرفه‌ای نیست.',null],
            ['pages','تماس با مرکز','contact','اطلاعات تماس در محتوای نمایشی درج نشده است. این صفحه برای نمایش ساختار محتوای تماس است؛ نشانی و شماره تلفن را مدیر مرکز باید وارد کند.',null],
            ['faqs','چطور وقت بگیرم؟','booking-guide','از فهرست مشاوران، خدمت و زمان آزاد را انتخاب کنید. رزروهای این محیط نمایشی هستند.',null],
            ['faqs','جلسه آنلاین چگونه برگزار می‌شود؟','online-session','پس از تأیید رزرو، شیوه اتصال در اطلاعات جلسه نمایش داده خواهد شد.',null],
            ['posts','آشنایی با مدیریت استرس روزمره','stress-basics','تمرین تنفس آرام، زمان‌بندی واقع‌بینانه و گفت‌وگو با فرد مورد اعتماد می‌تواند به مدیریت فشار روزمره کمک کند. این مطلب آموزشی جایگزین ارزیابی تخصصی نیست.','/images/demo/article-placeholder.svg'],
            ['posts','گفت‌وگوی همدلانه در رابطه','empathetic-conversation','گوش‌دادن بدون قضاوت و بیان روشن نیازها، پایه‌ای برای گفت‌وگوی سازنده است.','/images/demo/article-placeholder.svg'],
            ['testimonial','بازخورد نمایشی مراجعه‌کننده','sample-feedback','«تجربه نمونه برای نمایش چیدمان بازخوردها» — نقل‌قول ساختگی','/images/demo/center-placeholder.svg'],
            ['gallery','فضای گفت‌وگو (تصویر نمونه)','sample-room','تصویر جایگزین نمایشی؛ تصویری از فضای واقعی مرکز نیست.','/images/demo/center-placeholder.svg'],
        ];
        foreach ($entries as [$type,$title,$slug,$body,$image]) CmsEntry::updateOrCreate(['type'=>$type,'slug'=>self::PREFIX.$slug],['title'=>$title,'body'=>$body,'image'=>$image,'extra'=>['demo'=>true],'is_active'=>true,'status'=>'published','published_at'=>now()]);
        foreach (['contact_notice'=>'اطلاعات تماس این محیط نمایشی است؛ نشانی و شماره تلفن واقعی درج نشده است.','timezone'=>'Asia/Tehran','demo_mode'=>'true'] as $key=>$value) DB::table('cms_settings')->updateOrInsert(['key'=>self::PREFIX.$key],['value'=>$value,'created_at'=>now(),'updated_at'=>now()]);
    }
}
