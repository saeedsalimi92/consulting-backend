<?php
namespace App\Http\Controllers\Booking;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BookingSettingsController extends Controller
{
    public function gateways()
    {
        $fakeAvailable=app()->environment(['local','testing']);
        $active=$fakeAvailable && DB::table('booking_gateway_settings')->where('enabled',true)->where('provider','fake_bank')->value('provider') ? 'fake_bank' : null;
        return response()->json(['data'=>[
            ['provider'=>'fake_bank','enabled'=>(bool)DB::table('booking_gateway_settings')->where('provider','fake_bank')->value('enabled'),
                'implemented'=>true,'ready'=>$fakeAvailable,'available'=>$fakeAvailable,'mode'=>'sandbox','active'=>$active==='fake_bank','label'=>'Test payment'],
            ['provider'=>'zarinpal','enabled'=>(bool)DB::table('booking_gateway_settings')->where('provider','zarinpal')->value('enabled'),
                'implemented'=>false,'ready'=>false,'missing_configuration'=>['official contract and merchant credentials'],'mode'=>'sandbox','active'=>false],
            ['provider'=>'snappay','enabled'=>(bool)DB::table('booking_gateway_settings')->where('provider','snappay')->value('enabled'),
                'implemented'=>false,'ready'=>false,'missing_configuration'=>['official merchant contract, credentials and sandbox'],'mode'=>'sandbox','active'=>false],
        ],'active_provider'=>$active]);
    }

    public function updateGateway(Request $request)
    {
        $v=$request->validate(['active_provider'=>'required|in:fake_bank,zarinpal','enabled'=>'sometimes|boolean','mode'=>'sometimes|in:sandbox,production']);
        abort_unless(app()->environment(['local','testing']),422,'Fake Bank cannot be configured in production.');
        abort_unless($v['active_provider']==='fake_bank',422,'Zarinpal integration is not implemented');
        abort_if(array_key_exists('enabled',$v) && !$v['enabled'],422,'At least one payment gateway must remain active');
        DB::transaction(function()use($request,$v) {
            DB::table('booking_gateway_settings')->where('provider','fake_bank')->update(['enabled'=>$v['enabled']??true,
                'mode'=>$v['mode']??'sandbox','updated_at'=>now()]);
            DB::table('booking_gateway_settings')->where('provider','zarinpal')->update(['enabled'=>false,'updated_at'=>now()]);
            DB::table('booking_audit_events')->insert(['actor_id'=>$request->user()->id,'action'=>'gateway.changed',
                'details'=>json_encode(['provider'=>'fake_bank','mode'=>$v['mode']??'sandbox']),'created_at'=>now(),'updated_at'=>now()]);
        });
        return $this->gateways();
    }

    public function policy() { return response()->json(['data'=>DB::table('booking_policy_settings')->pluck('value','key')]); }

    public function updatePolicy(Request $request)
    {
        $v=$request->validate(['cancellation_penalty_percent'=>'sometimes|integer|between:0,100',
            'cancellation_penalty_basis'=>'sometimes|in:paid,total','cancellation_cutoff_hours'=>'sometimes|integer|between:0,720',
            'reschedule_cutoff_hours'=>'sometimes|integer|between:0,720','max_client_reschedules'=>'sometimes|integer|between:0,20',
            'hold_minutes'=>'sometimes|integer|between:5,120','connection_release_minutes'=>'sometimes|integer|between:0,120']);
        foreach($v as $key=>$value) DB::table('booking_policy_settings')->updateOrInsert(['key'=>$key],['value'=>(string)$value,'updated_at'=>now(),'created_at'=>now()]);
        DB::table('booking_audit_events')->insert(['actor_id'=>$request->user()->id,'action'=>'booking.policy_changed',
            'details'=>json_encode($v),'created_at'=>now(),'updated_at'=>now()]);
        return $this->policy();
    }

    public function taxRules()
    {
        return response()->json(['data'=>DB::table('booking_service_tax_rules as r')->join('booking_services as s','s.id','=','r.booking_service_id')
            ->select('r.*','s.title as service_name')->orderBy('r.booking_service_id')->orderByDesc('r.effective_from')->get()]);
    }

    public function saveTaxRule(Request $request,int $service)
    {
        abort_unless(DB::table('booking_services')->where('id',$service)->exists(),404);
        $v=$request->validate(['rate_basis_points'=>'required|integer|between:0,10000','enabled'=>'required|boolean','inclusive'=>'required|boolean',
            'effective_from'=>'required|date_format:Y-m-d','effective_to'=>'nullable|date_format:Y-m-d|after_or_equal:effective_from']);
        $id=DB::table('booking_service_tax_rules')->insertGetId([...$v,'booking_service_id'=>$service,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('booking_audit_events')->insert(['actor_id'=>$request->user()->id,'action'=>'tax.rule_changed',
            'details'=>json_encode(['service_id'=>$service,'rule_id'=>$id]),'created_at'=>now(),'updated_at'=>now()]);
        return response()->json(['data'=>DB::table('booking_service_tax_rules')->find($id)],201);
    }
}
