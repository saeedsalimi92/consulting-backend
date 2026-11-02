<?php

namespace App\Http\Controllers\Booking;

use App\Http\Controllers\Controller;
use App\Services\BookingNotices;
use App\Services\BookingResponse;
use App\Services\BookingPricing;
use App\Services\BookingSlots;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReceptionController extends Controller
{
    public function dashboard()
    {
        $now = now(BookingSlots::TIMEZONE);
        $todayStart = $now->copy()->startOfDay()->utc()->toDateTimeString();
        $tomorrow = $now->copy()->addDay()->startOfDay()->utc()->toDateTimeString();
        $active = ['held', 'confirmed', 'checked_in', 'in_progress'];
        $today = DB::table('bookings as b')->join('users as u', 'u.id', '=', 'b.user_id')
            ->join('counselors as c', 'c.id', '=', 'b.counselor_id')->join('booking_services as s', 's.id', '=', 'b.booking_service_id')
            ->leftJoin('appointment_rooms as r', 'r.id', '=', 'b.room_id')->where('b.starts_at', '>=', $todayStart)->where('b.starts_at', '<', $tomorrow)
            ->select('b.*', 'u.name as client_name', 'u.phone as client_phone', 'c.name as counselor_name', 's.title as service_name', 'r.name as room_name')
            ->orderBy('b.starts_at')->get();
        $upcoming = DB::table('bookings as b')->join('users as u', 'u.id', '=', 'b.user_id')->join('counselors as c', 'c.id', '=', 'b.counselor_id')
            ->join('booking_services as s', 's.id', '=', 'b.booking_service_id')->whereIn('b.status', $active)->where('b.starts_at', '>=', $tomorrow)
            ->select('b.*', 'u.name as client_name', 'c.name as counselor_name', 's.title as service_name')->orderBy('b.starts_at')->limit(20)->get();
        $cancellations = DB::table('bookings')->where('status', 'cancelled')->where('starts_at', '>=', $todayStart)->where('starts_at', '<', $tomorrow)->count();
        $noShows = DB::table('bookings')->where('status', 'no_show')->where('starts_at', '>=', $todayStart)->where('starts_at', '<', $tomorrow)->count();
        $occupiedRooms = DB::table('bookings as b')->join('appointment_rooms as r', 'r.id', '=', 'b.room_id')
            ->whereIn('b.status', ['checked_in', 'in_progress'])->where('b.starts_at', '<=', now()->utc())->where('b.ends_at', '>', now()->utc())
            ->select('r.id', 'r.name', 'b.id as booking_id', 'b.ends_at')->get();
        return response()->json(['timezone' => BookingSlots::TIMEZONE, 'data' => [
            'today' => $today->map(fn ($row) => BookingResponse::present($row)),
            'upcoming' => $upcoming->map(fn ($row) => BookingResponse::present($row)),
            'pending_confirmations' => $today->where('status', 'held')->values(),
            'cancellations_today' => $cancellations, 'no_shows_today' => $noShows,
            'available_counselors' => $this->availableCounselors($now), 'occupied_rooms' => $occupiedRooms,
        ]]);
    }

    private function availableCounselors(Carbon $now)
    {
        $clock = $now->format('H:i:s');
        $scheduled = DB::table('counselors as c')->join('counselor_working_hours as h', 'h.counselor_id', '=', 'c.id')
            ->where('c.is_active', true)->whereNull('c.deleted_at')->where('h.is_active', true)->where('h.weekday', $now->dayOfWeek)
            ->where('h.starts_at', '<=', $clock)->where('h.ends_at', '>', $clock)->select('c.id', 'c.name')->distinct()->orderBy('c.name')->get();
        $busy = DB::table('bookings')->where('starts_at', '<=', now()->utc())->where('ends_at', '>', now()->utc())
            ->where(fn ($q) => $q->whereIn('status', ['held', 'confirmed', 'checked_in', 'in_progress']))->pluck('counselor_id')->map(fn ($id) => (int) $id)->all();
        $off = DB::table('counselor_time_off')->where('starts_at', '<=', now()->utc())->where('ends_at', '>', now()->utc())
            ->pluck('counselor_id')->map(fn ($id) => (int) $id)->all();
        return $scheduled->reject(fn ($counselor) => in_array((int) $counselor->id, $busy, true) || in_array((int) $counselor->id, $off, true))->values();
    }

    public function rooms(Request $request)
    {
        $v = $request->validate(['date' => 'nullable|date_format:Y-m-d']);
        $date = Carbon::createFromFormat('!Y-m-d', $v['date'] ?? now(BookingSlots::TIMEZONE)->toDateString(), BookingSlots::TIMEZONE);
        $from = $date->copy()->startOfDay()->utc()->toDateTimeString();
        $to = $date->copy()->addDay()->startOfDay()->utc()->toDateTimeString();
        $rooms = DB::table('appointment_rooms as r')->leftJoin('bookings as b', function ($join) use ($from, $to) {
            $join->on('b.room_id', '=', 'r.id')->where('b.starts_at', '<', $to)->where('b.ends_at', '>', $from)
                ->whereIn('b.status', ['held', 'confirmed', 'checked_in', 'in_progress']);
        })->select('r.*', DB::raw('COUNT(b.id) as appointments_count'))->groupBy('r.id')->orderBy('r.name')->get();
        return response()->json(['timezone' => BookingSlots::TIMEZONE, 'date' => $date->toDateString(), 'data' => $rooms]);
    }

    public function roomStore(Request $request)
    {
        $v = $request->validate(['name' => 'required|string|max:120|unique:appointment_rooms,name', 'location' => 'nullable|string|max:255',
            'capacity' => 'sometimes|integer|min:1|max:100', 'is_active' => 'sometimes|boolean']);
        $id = DB::table('appointment_rooms')->insertGetId([...$v, 'capacity' => $v['capacity'] ?? 1, 'is_active' => $v['is_active'] ?? true,
            'created_at' => now(), 'updated_at' => now()]);
        $this->audit($request, null, 'reception.room_created', ['room_id' => $id]);
        return response()->json(['data' => DB::table('appointment_rooms')->find($id)], 201);
    }

    public function roomUpdate(Request $request, int $room)
    {
        $v = $request->validate(['name' => 'sometimes|required|string|max:120|unique:appointment_rooms,name,'.$room, 'location' => 'nullable|string|max:255',
            'capacity' => 'sometimes|integer|min:1|max:100', 'is_active' => 'sometimes|boolean']);
        abort_unless(DB::table('appointment_rooms')->where('id', $room)->exists(), 404);
        DB::transaction(function () use ($room, $v) {
            $currentRoom=DB::table('appointment_rooms')->where('id', $room)->lockForUpdate()->first();
            abort_unless($currentRoom,404);
            if(isset($v['capacity']) && $v['capacity'] < $currentRoom->capacity) abort_if(DB::table('bookings')->where('room_id',$room)->where('attendee_count','>',$v['capacity'])->whereIn('status',['held','confirmed','checked_in','in_progress'])->where('ends_at','>',now()->utc())->exists(),409,'Room capacity is below an assigned appointment attendee count.');
            if (($v['is_active'] ?? true) === false) abort_if(DB::table('bookings')->where('room_id', $room)->whereIn('status', ['held', 'confirmed', 'checked_in', 'in_progress'])->where('ends_at', '>', now()->utc())->exists(), 409, 'Room has upcoming appointments.');
            DB::table('appointment_rooms')->where('id', $room)->update([...$v, 'updated_at' => now()]);
        }, 3);
        $this->audit($request, null, 'reception.room_updated', ['room_id' => $room, 'fields' => array_keys($v)]);
        return response()->json(['data' => DB::table('appointment_rooms')->find($room)]);
    }

    public function roomCalendar(Request $request, int $room)
    {
        $v = $request->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from',
            'per_page' => 'nullable|integer|min:1|max:100']);
        abort_unless(DB::table('appointment_rooms')->where('id', $room)->exists(), 404);
        $first = Carbon::createFromFormat('!Y-m-d', $v['from'], BookingSlots::TIMEZONE)->startOfDay()->utc();
        $last = Carbon::createFromFormat('!Y-m-d', $v['to'], BookingSlots::TIMEZONE)->addDay()->startOfDay()->utc();
        abort_if($first->diffInDays($last) > 367, 422, 'Room calendar range cannot exceed 366 days.');
        $events = DB::table('bookings as b')->join('users as u', 'u.id', '=', 'b.user_id')
            ->join('counselors as c', 'c.id', '=', 'b.counselor_id')->join('booking_services as s', 's.id', '=', 'b.booking_service_id')
            ->where('b.room_id', $room)->where('b.starts_at', '<', $last->toDateTimeString())->where('b.ends_at', '>', $first->toDateTimeString())
            ->select('b.id as booking_id', 'b.starts_at', 'b.ends_at', 'b.status', 'b.attendee_count', 'u.name as client_name', 'c.name as counselor_name', 's.title as service_name')
            ->orderBy('b.starts_at')->paginate($v['per_page'] ?? 50);
        return response()->json(['timezone' => BookingSlots::TIMEZONE, 'room_id' => $room, 'data' => $events]);
    }

    public function roomHours(Request $request,int $room)
    {
        abort_unless(DB::table('appointment_rooms')->where('id',$room)->exists(),404);
        if($request->isMethod('get'))return response()->json(['timezone'=>BookingSlots::TIMEZONE,'data'=>DB::table('appointment_room_working_hours')->where('room_id',$room)->orderBy('weekday')->orderBy('opens_at')->get()]);
        $v=$request->validate(['hours'=>'required|array|max:21','hours.*.weekday'=>'required|integer|between:0,6','hours.*.opens_at'=>'required|date_format:H:i','hours.*.closes_at'=>'required|date_format:H:i|after:hours.*.opens_at']);
        foreach($v['hours'] as $i=>$a)foreach($v['hours'] as $j=>$b)if($i<$j&&$a['weekday']===$b['weekday']&&$a['opens_at']<$b['closes_at']&&$a['closes_at']>$b['opens_at'])abort(422,'Room working intervals cannot overlap.');
        return DB::transaction(function()use($request,$room,$v){DB::table('appointment_rooms')->where('id',$room)->lockForUpdate()->first();
            $future=DB::table('bookings')->where('room_id',$room)->whereIn('status',['held','confirmed','checked_in','in_progress'])->where('ends_at','>',now()->utc())->get(['starts_at','ends_at']);
            foreach($future as $booking)abort_unless($this->intervalCovered($v['hours'],Carbon::parse($booking->starts_at,'UTC'),Carbon::parse($booking->ends_at,'UTC')),409,'Working-hour change conflicts with an existing booking.');
            DB::table('appointment_room_working_hours')->where('room_id',$room)->delete();foreach($v['hours'] as $hour)DB::table('appointment_room_working_hours')->insert([...$hour,'room_id'=>$room,'created_at'=>now(),'updated_at'=>now()]);
            $this->audit($request,null,'reception.room_hours_updated',['room_id'=>$room,'intervals'=>count($v['hours'])]);return response()->json(['data'=>DB::table('appointment_room_working_hours')->where('room_id',$room)->orderBy('weekday')->orderBy('opens_at')->get()]);
        },3);
    }

    public function roomUnavailability(Request $request,int $room)
    {
        abort_unless(DB::table('appointment_rooms')->where('id',$room)->exists(),404);
        if($request->isMethod('get')) return response()->json(['data'=>DB::table('appointment_room_unavailability')->where('room_id',$room)->where('ends_at','>',now()->utc())->orderBy('starts_at')->paginate(50)]);
        $v=$request->validate(['starts_at'=>'required|date_format:Y-m-d\\TH:i:sP','ends_at'=>'required|date_format:Y-m-d\\TH:i:sP|after:starts_at','reason'=>'nullable|string|max:255']);
        $start=Carbon::parse($v['starts_at'])->utc();$end=Carbon::parse($v['ends_at'])->utc();
        return DB::transaction(function()use($request,$room,$v,$start,$end){
            DB::table('appointment_rooms')->where('id',$room)->lockForUpdate()->first();
            abort_if(DB::table('bookings')->where('room_id',$room)->whereIn('status',['held','confirmed','checked_in','in_progress'])
                ->where('starts_at','<',$end)->where('ends_at','>',$start)->exists(),409,'Room has an appointment during this maintenance window.');
            $id=DB::table('appointment_room_unavailability')->insertGetId(['room_id'=>$room,'starts_at'=>$start,'ends_at'=>$end,'reason'=>$v['reason']??null,'created_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now()]);
            $this->audit($request,null,'reception.room_unavailable',['room_id'=>$room,'window_id'=>$id]);
            return response()->json(['data'=>DB::table('appointment_room_unavailability')->find($id)],201);
        },3);
    }
    public function deleteRoomUnavailability(Request $request,int $window)
    {
        $row=DB::table('appointment_room_unavailability')->find($window);abort_unless($row,404);
        DB::table('appointment_room_unavailability')->where('id',$window)->delete();$this->audit($request,null,'reception.room_availability_restored',['window_id'=>$window]);return response()->noContent();
    }
    public function holidays(Request $request)
    {
        if($request->isMethod('get'))return response()->json(['data'=>DB::table('center_holidays')->orderBy('holiday_date')->paginate(100)]);
        $v=$request->validate(['holiday_date'=>'required|date_format:Y-m-d|unique:center_holidays,holiday_date','title'=>'nullable|string|max:180','is_open'=>'sometimes|boolean','opens_at'=>'nullable|date_format:H:i','closes_at'=>'nullable|date_format:H:i|after:opens_at']);
        abort_if(($v['is_open']??false)&&empty($v['opens_at']),422,'An open holiday requires operating hours.');
        if(!($v['is_open']??false))abort_if(DB::table('bookings')->whereIn('status',['held','confirmed','checked_in','in_progress'])->where('starts_at','>=',Carbon::parse($v['holiday_date'],BookingSlots::TIMEZONE)->startOfDay()->utc())->where('starts_at','<',Carbon::parse($v['holiday_date'],BookingSlots::TIMEZONE)->addDay()->startOfDay()->utc())->exists(),409,'Cannot close the center on a date with scheduled appointments.');
        $id=DB::table('center_holidays')->insertGetId([...$v,'is_open'=>$v['is_open']??false,'created_at'=>now(),'updated_at'=>now()]);$this->audit($request,null,'center.holiday_created',['holiday_id'=>$id]);
        return response()->json(['data'=>DB::table('center_holidays')->find($id)],201);
    }
    public function deleteHoliday(Request $request,int $holiday)
    {
        abort_unless(DB::table('center_holidays')->where('id',$holiday)->delete(),404);$this->audit($request,null,'center.holiday_deleted',['holiday_id'=>$holiday]);return response()->noContent();
    }

    public function roomAvailability(Request $request, int $room)
    {
        $v = $request->validate(['starts_at' => 'required|date_format:Y-m-d\TH:i:sP', 'ends_at' => 'required|date_format:Y-m-d\TH:i:sP|after:starts_at','attendee_count'=>'nullable|integer|min:1|max:100']);
        $row = DB::table('appointment_rooms')->where('id', $room)->first();
        abort_unless($row, 404);
        $start = Carbon::parse($v['starts_at'])->utc(); $end = Carbon::parse($v['ends_at'])->utc();
        $conflict = DB::table('bookings')->where('room_id', $room)->where('starts_at', '<', $end->toDateTimeString())->where('ends_at', '>', $start->toDateTimeString())
            ->where(fn($q) => $q->whereIn('status', ['confirmed','checked_in','in_progress'])->orWhere(fn($held) => $held->where('status','held')->where('hold_expires_at','>',now()->utc())))->exists();
        $overCapacity=isset($v['attendee_count'])&&$v['attendee_count']>(int)$row->capacity;
        $scheduleAvailable=$this->intervalCovered(DB::table('appointment_room_working_hours')->where('room_id',$room)->get()->all(),$start,$end);
        $maintenance=DB::table('appointment_room_unavailability')->where('room_id',$room)->where('starts_at','<',$end)->where('ends_at','>',$start)->exists();
        $localStart=$start->copy()->setTimezone(BookingSlots::TIMEZONE);$localEnd=$end->copy()->subSecond()->setTimezone(BookingSlots::TIMEZONE);
        $holidayRows=DB::table('center_holidays')->whereBetween('holiday_date',[$localStart->toDateString(),$localEnd->toDateString()])->get();
        $holiday=false;foreach($holidayRows as $h){if(!$h->is_open){$holiday=true;break;}$dayStart=Carbon::parse($h->holiday_date.' '.$h->opens_at,BookingSlots::TIMEZONE);$dayEnd=Carbon::parse($h->holiday_date.' '.$h->closes_at,BookingSlots::TIMEZONE);if(($localStart->toDateString()===$h->holiday_date&&$localStart->lt($dayStart))||($localEnd->toDateString()===$h->holiday_date&&$localEnd->gte($dayEnd))){$holiday=true;break;}}
        $centerOutside=!$this->centerHoursCover($start,$end);
        $closed=!$row->is_active||$holiday||$maintenance||!$scheduleAvailable||$centerOutside;
        return response()->json(['data' => ['room_id' => $room, 'available' => !$closed && !$conflict && !$overCapacity, 'conflict' => $conflict,'maintenance'=>$maintenance,'center_holiday'=>$holiday,'outside_center_hours'=>$centerOutside,'outside_working_hours'=>!$scheduleAvailable,'capacity'=>$row->capacity,'over_capacity'=>$overCapacity]]);
    }

    public function roomDestroy(Request $request, int $room)
    {
        return DB::transaction(function () use ($request, $room) {
            $row = DB::table('appointment_rooms')->where('id', $room)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_if(DB::table('bookings')->where('room_id', $room)->exists(), 409, 'Room has booking history; deactivate it instead.');
            DB::table('appointment_rooms')->where('id', $room)->delete();
            $this->audit($request, null, 'reception.room_deleted', ['room_id' => $room]);
            return response()->noContent();
        }, 3);
    }

    public function checkIn(Request $request, int $booking)
    {
        return DB::transaction(function () use ($request, $booking) {
            $row = DB::table('bookings')->where('id', $booking)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless($row->status === 'confirmed', 409, 'Only confirmed appointments can check in.');
            DB::table('bookings')->where('id', $booking)->update(['status' => 'checked_in', 'checked_in_at' => now(), 'updated_at' => now()]);
            $this->audit($request, $booking, 'session.checked_in', []);
            return response()->json(['data' => BookingResponse::present(DB::table('bookings')->find($booking))]);
        }, 3);
    }

    public function assignRoom(Request $request, int $booking)
    {
        $v = $request->validate(['room_id' => 'nullable|integer|exists:appointment_rooms,id','attendee_count'=>'sometimes|integer|min:1|max:100']);
        return DB::transaction(function () use ($request, $booking, $v) {
            $row = DB::table('bookings')->where('id', $booking)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless(in_array($row->status, ['held', 'confirmed', 'checked_in'], true), 409);
            if (!empty($v['room_id'])) {
                $room = DB::table('appointment_rooms')->where('id', $v['room_id'])->lockForUpdate()->first();
                abort_unless($room && $room->is_active, 422, 'Room is inactive.');
                if(($v['attendee_count']??(int)($row->attendee_count??1))>(int)$room->capacity)throw ValidationException::withMessages(['attendee_count'=>'Attendee count exceeds room capacity.']);
                $this->assertRoomNotClosed((int)$room->id,Carbon::parse($row->starts_at,'UTC'),Carbon::parse($row->ends_at,'UTC'));
                $conflict = DB::table('bookings')->where('room_id', $room->id)->where('id', '<>', $booking)
                    ->where('starts_at', '<', $row->ends_at)->where('ends_at', '>', $row->starts_at)
                    ->where(fn ($q) => $q->whereIn('status', ['confirmed', 'checked_in', 'in_progress'])
                        ->orWhere(fn ($q) => $q->where('status', 'held')->where('hold_expires_at', '>', now()->utc())))->exists();
                if ($conflict) throw ValidationException::withMessages(['room_id' => 'Room is already booked for this time.']);
            }
            DB::table('bookings')->where('id', $booking)->update(['room_id' => $v['room_id'] ?? null,'attendee_count'=>$v['attendee_count']??(int)($row->attendee_count??1),
                'mode' => empty($v['room_id']) ? 'online' : 'in_person', 'session_mode' => empty($v['room_id']) ? 'meet' : 'in_person', 'updated_at' => now()]);
            $this->audit($request, $booking, 'reception.room_assigned', ['room_id' => $v['room_id'] ?? null]);
            return response()->json(['data' => BookingResponse::present(DB::table('bookings')->find($booking))]);
        }, 3);
    }

    public function waitlist(Request $request)
    {
        $v = $request->validate(['status' => 'nullable|in:active,notified,assigned,cancelled', 'counselor_id' => 'nullable|exists:counselors,id',
            'service_id' => 'nullable|exists:booking_services,id', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d']);
        $query = DB::table('booking_waitlist as w')->join('users as u', 'u.id', '=', 'w.user_id')->join('counselors as c', 'c.id', '=', 'w.counselor_id')
            ->join('booking_services as s', 's.id', '=', 'w.booking_service_id')->leftJoin('bookings as b', 'b.id', '=', 'w.assigned_booking_id')
            ->select('w.*', 'u.name as client_name', 'u.phone as client_phone', 'c.name as counselor_name', 's.title as service_name', 'b.status as booking_status')
            ->when($v['status'] ?? null, fn ($q, $status) => $q->where('w.status', $status))
            ->when($v['counselor_id'] ?? null, fn ($q, $id) => $q->where('w.counselor_id', $id))
            ->when($v['service_id'] ?? null, fn ($q, $id) => $q->where('w.booking_service_id', $id));
        if (isset($v['from'])) $query->where('w.starts_at', '>=', Carbon::parse($v['from'], BookingSlots::TIMEZONE)->startOfDay()->utc());
        if (isset($v['to'])) $query->where('w.starts_at', '<', Carbon::parse($v['to'], BookingSlots::TIMEZONE)->addDay()->startOfDay()->utc());
        return response()->json(['data' => $query->orderBy('w.starts_at')->paginate(30)]);
    }

    public function assignWaitlist(Request $request, int $entry, BookingSlots $slots, BookingNotices $notices)
    {
        $v = $request->validate(['payment_option' => 'sometimes|in:full,deposit', 'room_id' => 'nullable|integer|exists:appointment_rooms,id','attendee_count'=>'sometimes|integer|min:1|max:100']);
        $result = DB::transaction(function () use ($request, $entry, $slots, $notices, $v) {
            $item = DB::table('booking_waitlist')->where('id', $entry)->lockForUpdate()->first();
            abort_unless($item, 404);
            abort_unless(in_array($item->status, ['active', 'notified'], true), 409);
            abort_if($item->status === 'notified' && $item->offer_expires_at && $item->offer_expires_at <= now()->utc(), 409, 'Waiting-list offer expired.');
            DB::table('counselors')->where('id', $item->counselor_id)->lockForUpdate()->first();
            $service = $slots->service((int) $item->counselor_id, (int) $item->booking_service_id);
            $start = Carbon::parse($item->starts_at, 'UTC');
            $slots->requireAvailable((int) $item->counselor_id, $service, $start);
            $room = $v['room_id'] ?? null;
            if ($room) { $this->assertRoomAvailable((int) $room, $start, $start->copy()->addMinutes($service->duration_minutes));$capacity=(int)DB::table('appointment_rooms')->where('id',$room)->value('capacity');if(($v['attendee_count']??1)>$capacity)throw ValidationException::withMessages(['attendee_count'=>'Attendee count exceeds room capacity.']); }
            $option = $v['payment_option'] ?? 'full';
            $pricing = BookingPricing::snapshot((int) $service->id, (int) $service->effective_price_irr, $service->commission_type,
                (string) $service->commission_value, $option, (int) $service->deposit_irr);
            $id = DB::table('bookings')->insertGetId(['user_id' => $item->user_id, 'created_by' => $request->user()->id,
                'counselor_id' => $item->counselor_id, 'room_id' => $room, 'booking_service_id' => $service->id,
                'mode' => $room ? 'in_person' : 'online', 'session_mode' => $room ? 'in_person' : 'meet','attendee_count'=>$v['attendee_count']??1,
                'starts_at' => $start->toDateTimeString(), 'ends_at' => $start->copy()->addMinutes($service->duration_minutes)->toDateTimeString(),
                'status' => 'held', 'payment_option' => $option, 'total_irr' => $pricing['total_irr'], 'required_irr' => $pricing['required_irr'],
                'commission_total_irr' => $pricing['commission_total_irr'], 'duration_minutes' => $service->duration_minutes,
                'tax_irr' => $pricing['tax_irr'], 'tax_rate_basis_points' => $pricing['tax_rate_basis_points'],
                'base_price_irr' => $pricing['base_price_irr'], 'center_share_irr' => $pricing['center_share_irr'],
                'hold_expires_at' => now()->utc()->addMinutes(20), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('booking_waitlist')->where('id', $entry)->update(['status' => 'assigned', 'assigned_booking_id' => $id, 'updated_at' => now()]);
            DB::table('booking_audit_events')->insert(['actor_id' => $request->user()->id, 'booking_id' => $id,
                'action' => 'waitlist.assigned', 'details' => json_encode(['waitlist_id' => $entry]), 'created_at' => now(), 'updated_at' => now()]);
            $notices->queue($id, 'waitlist-assigned-'.$entry, 'client', 'in_app');
            return DB::table('bookings')->find($id);
        }, 3);
        return response()->json(['data' => BookingResponse::present($result)], 201);
    }

    private function assertRoomAvailable(int $room, Carbon $start, Carbon $end): void
    {
        $roomRow=DB::table('appointment_rooms')->where('id', $room)->lockForUpdate()->first();abort_unless($roomRow&&$roomRow->is_active,422,'Room is not available.');
        $this->assertRoomNotClosed($room,$start,$end);
        $exists = DB::table('bookings')->where('room_id', $room)->where('starts_at', '<', $end->toDateTimeString())
            ->where('ends_at', '>', $start->toDateTimeString())->where(fn ($q) => $q->whereIn('status', ['confirmed', 'checked_in', 'in_progress'])
                ->orWhere(fn ($q) => $q->where('status', 'held')->where('hold_expires_at', '>', now()->utc())))->exists();
        if ($exists) throw ValidationException::withMessages(['room_id' => 'Room is already booked for this time.']);
    }
    private function assertRoomNotClosed(int $room,Carbon $start,Carbon $end):void
    {
        if(DB::table('appointment_room_unavailability')->where('room_id',$room)->where('starts_at','<',$end)->where('ends_at','>',$start)->exists())throw ValidationException::withMessages(['room_id'=>'Room is under maintenance during this period.']);
        abort_unless($this->centerHoursCover($start,$end),422,'Center is closed or outside operating hours.');
        $localStart=$start->copy()->setTimezone(BookingSlots::TIMEZONE);$localEnd=$end->copy()->subSecond()->setTimezone(BookingSlots::TIMEZONE);
        foreach(DB::table('center_holidays')->whereBetween('holiday_date',[$localStart->toDateString(),$localEnd->toDateString()])->get() as $holiday){if(!$holiday->is_open)throw ValidationException::withMessages(['room_id'=>'Center is closed on this date.']);$opens=Carbon::parse($holiday->holiday_date.' '.$holiday->opens_at,BookingSlots::TIMEZONE);$closes=Carbon::parse($holiday->holiday_date.' '.$holiday->closes_at,BookingSlots::TIMEZONE);if(($localStart->toDateString()===$holiday->holiday_date&&$localStart->lt($opens))||($localEnd->toDateString()===$holiday->holiday_date&&$localEnd->gte($closes)))throw ValidationException::withMessages(['room_id'=>'Appointment is outside holiday opening hours.']);}
        abort_unless($this->intervalCovered(DB::table('appointment_room_working_hours')->where('room_id',$room)->get()->all(),$start,$end),422,'Room is outside its weekly working hours.');
    }
    private function centerHoursCover(Carbon $start,Carbon $end):bool
    {
        $a=$start->copy()->setTimezone(BookingSlots::TIMEZONE);$b=$end->copy()->setTimezone(BookingSlots::TIMEZONE);
        while($a->lt($b)){$next=$a->copy()->startOfDay()->addDay();$segmentEnd=$b->lt($next)?$b:$next;$date=$a->toDateString();$special=DB::table('center_holidays')->where('holiday_date',$date)->first();$hours=$special??DB::table('center_operating_hours')->where('weekday',$a->dayOfWeek)->first();
            if($hours){if(!$hours->is_open)return false;$opens=Carbon::parse($date.' '.$hours->opens_at,BookingSlots::TIMEZONE);$closes=Carbon::parse($date.' '.$hours->closes_at,BookingSlots::TIMEZONE);if($a->lt($opens)||$segmentEnd->gt($closes))return false;}
            $a=$segmentEnd;
        }return true;
    }
    private function intervalCovered(iterable $hours,Carbon $start,Carbon $end):bool
    {
        $hours=collect($hours);if($hours->isEmpty())return true;$cursor=$start->copy()->setTimezone(BookingSlots::TIMEZONE);$finish=$end->copy()->setTimezone(BookingSlots::TIMEZONE);
        while($cursor->lt($finish)){$dayEnd=$cursor->copy()->endOfDay()->addSecond();$segmentEnd=$finish->lt($dayEnd)?$finish:$dayEnd;$clockStart=$cursor->format('H:i:s');$clockEnd=$segmentEnd->format('H:i:s');$covered=$hours->contains(function($h)use($cursor,$clockStart,$clockEnd){$weekday=is_array($h)?$h['weekday']:$h->weekday;$opens=is_array($h)?$h['opens_at']:$h->opens_at;$closes=is_array($h)?$h['closes_at']:$h->closes_at;return (int)$weekday===$cursor->dayOfWeek&&$opens<=$clockStart&&$closes>=$clockEnd;});if(!$covered)return false;$cursor=$segmentEnd;}
        return true;
    }

    private function audit(Request $request, ?int $booking, string $action, array $details): void
    {
        DB::table('booking_audit_events')->insert(['actor_id' => $request->user()->id, 'booking_id' => $booking,
            'action' => $action, 'details' => json_encode($details), 'created_at' => now(), 'updated_at' => now()]);
    }
}
