<?php

namespace App\Http\Controllers\Booking;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ClinicalController extends Controller
{
    private function assignedCounselorId(Request $request): int
    {
        $id = (int) DB::table('counselors')->where('user_id', $request->user()->id)->value('id');
        abort_unless($id > 0, 403);
        return $id;
    }

    private function ensureClinicalAccess(Request $request, int $clientId, ?int $counselorId = null): void
    {
        if ($request->user()->canAccessAdmin()) {
            abort_unless($request->user()->hasAdminPermission('notes.manage'), 403);
            return;
        }
        $assigned = $this->assignedCounselorId($request);
        abort_if($counselorId !== null && $counselorId !== $assigned, 404);
        abort_unless(DB::table('bookings')->where('user_id', $clientId)->where('counselor_id', $assigned)->exists(), 404);
    }

    public function record(Request $request, int $client)
    {
        $this->ensureClinicalAccess($request, $client);
        if ($request->isMethod('get')) {
            $record = DB::table('client_clinical_records')->where('client_id', $client)->first();
            $this->audit($request, null, 'clinical.record_accessed', ['client_id' => $client]);
            return response()->json(['data' => $record]);
        }
        $v = $request->validate([
            'primary_counselor_id' => 'nullable|integer|exists:counselors,id',
            'client_concerns' => 'nullable|string|max:10000',
            'treatment_goals' => 'nullable|array|max:100',
            'treatment_goals.*' => 'string|max:1000',
            'progress_summary' => 'nullable|string|max:10000',
        ]);
        if (!$request->user()->canAccessAdmin()) {
            unset($v['primary_counselor_id']);
            $v['primary_counselor_id'] = $this->assignedCounselorId($request);
        }
        DB::table('client_clinical_records')->updateOrInsert(['client_id' => $client], [
            ...$v, 'updated_by' => $request->user()->id, 'updated_at' => now(), 'created_at' => now(),
        ]);
        $this->audit($request, null, 'clinical.record_updated', ['client_id' => $client, 'fields' => array_keys($v)]);
        return response()->json(['data' => DB::table('client_clinical_records')->where('client_id', $client)->first()]);
    }

    public function forms(Request $request)
    {
        if ($request->user()->canAccessAdmin()) abort_unless($request->user()->hasAdminPermission('notes.manage'), 403);
        if ($request->isMethod('get')) {
            $forms = DB::table('intake_forms as f')->leftJoin('intake_form_versions as v', function ($join) {
                $join->on('v.intake_form_id', '=', 'f.id')->whereNotNull('v.published_at');
            })->select('f.*', 'v.id as published_version_id', 'v.version', 'v.schema', 'v.published_at')
                ->orderBy('f.title')->get();
            return response()->json(['data' => $forms]);
        }
        $v = $request->validate([
            'title' => 'required|string|max:180', 'key' => 'required|string|max:100|regex:/^[a-z0-9_-]+$/|unique:intake_forms,key',
            'schema' => 'required|array|min:1|max:100', 'schema.*.key' => 'required|string|max:100|distinct',
            'schema.*.label' => 'required|string|max:500', 'schema.*.type' => 'required|in:text,textarea,number,boolean,select,multiselect,date',
            'schema.*.required' => 'required|boolean', 'schema.*.options' => 'sometimes|array|max:100',
            'is_active' => 'sometimes|boolean',
        ]);
        $id = DB::transaction(function () use ($request, $v) {
            $form = DB::table('intake_forms')->insertGetId(['title' => $v['title'], 'key' => $v['key'],
                'is_active' => $v['is_active'] ?? true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('intake_form_versions')->insert(['intake_form_id' => $form, 'version' => 1,
                'schema' => json_encode($v['schema']), 'published_by' => $request->user()->id, 'published_at' => now(),
                'created_at' => now(), 'updated_at' => now()]);
            return $form;
        });
        $this->audit($request, null, 'clinical.intake_form_created', ['form_id' => $id]);
        return response()->json(['data' => DB::table('intake_forms')->find($id)], 201);
    }

    public function intakeForm(int $form)
    {
        $row = DB::table('intake_form_versions as v')->join('intake_forms as f', 'f.id', '=', 'v.intake_form_id')
            ->where('f.id', $form)->where('f.is_active', true)->whereNotNull('v.published_at')
            ->orderByDesc('v.version')->select('f.id', 'f.key', 'f.title', 'v.version', 'v.schema')->first();
        abort_unless($row, 404);
        return response()->json(['data' => $row]);
    }

    public function newFormVersion(Request $request, int $form)
    {
        abort_unless($request->user()->canAccessAdmin() && $request->user()->hasAdminPermission('notes.manage'), 403);
        $v = $request->validate(['schema' => 'required|array|min:1|max:100', 'schema.*.key' => 'required|string|max:100|distinct',
            'schema.*.label' => 'required|string|max:500', 'schema.*.type' => 'required|in:text,textarea,number,boolean,select,multiselect,date',
            'schema.*.required' => 'required|boolean', 'schema.*.options' => 'sometimes|array|max:100']);
        abort_unless(DB::table('intake_forms')->where('id', $form)->exists(), 404);
        $version = (int) DB::table('intake_form_versions')->where('intake_form_id', $form)->max('version') + 1;
        DB::table('intake_form_versions')->insert(['intake_form_id' => $form, 'version' => $version, 'schema' => json_encode($v['schema']),
            'published_by' => $request->user()->id, 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->audit($request, null, 'clinical.intake_form_version_published', ['form_id' => $form, 'version' => $version]);
        return response()->json(['data' => DB::table('intake_form_versions')->where('intake_form_id', $form)->where('version', $version)->first()], 201);
    }

    public function submitIntake(Request $request, int $form)
    {
        $client = (int) $request->user()->id;
        $version = DB::table('intake_form_versions as v')->join('intake_forms as f', 'f.id', '=', 'v.intake_form_id')
            ->where('f.id', $form)->where('f.is_active', true)->whereNotNull('v.published_at')->orderByDesc('v.version')->select('v.*')->first();
        abort_unless($version, 404);
        $schema = json_decode($version->schema, true) ?: [];
        $v = $request->validate(['answers' => 'required|array']);
        $answers = $v['answers'];
        $knownKeys = array_column($schema, 'key');
        $unknown = array_diff(array_keys($answers), $knownKeys);
        if ($unknown) throw ValidationException::withMessages(['answers' => 'Unknown question keys: '.implode(', ', $unknown)]);
        $allowed = [];
        foreach ($schema as $field) {
            $key = $field['key'];
            if (($field['required'] ?? false) && (!array_key_exists($key, $answers) || $answers[$key] === '' || $answers[$key] === null)) {
                throw ValidationException::withMessages(["answers.$key" => 'This answer is required.']);
            }
            if (!array_key_exists($key, $answers)) continue;
            $value = $answers[$key];
            $valid = match ($field['type']) {
                'text', 'textarea' => is_string($value) && mb_strlen($value) <= 10000,
                'number' => is_numeric($value), 'boolean' => is_bool($value), 'date' => is_string($value) && strtotime($value) !== false,
                'select' => is_string($value) && in_array($value, $field['options'] ?? [], true),
                'multiselect' => is_array($value) && count(array_diff($value, $field['options'] ?? [])) === 0,
                default => false,
            };
            if (!$valid) throw ValidationException::withMessages(["answers.$key" => 'Answer does not match the question type.']);
            $allowed[$key] = $value;
        }
        $id = DB::table('intake_form_answers')->insertGetId(['client_id' => $client, 'intake_form_version_id' => $version->id,
            'answers' => json_encode($allowed), 'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['data' => ['id' => $id, 'form_version' => $version->version, 'submitted_at' => now()->toIso8601String()]], 201);
    }

    public function answers(Request $request, int $client)
    {
        $this->ensureClinicalAccess($request, $client);
        $this->audit($request, null, 'clinical.intake_answers_accessed', ['client_id' => $client]);
        return response()->json(['data' => DB::table('intake_form_answers as a')->join('intake_form_versions as v', 'v.id', '=', 'a.intake_form_version_id')
            ->join('intake_forms as f', 'f.id', '=', 'v.intake_form_id')->where('a.client_id', $client)
            ->select('a.id', 'f.title as form_title', 'v.version', 'a.answers', 'a.submitted_at')->orderByDesc('a.submitted_at')->paginate(30)]);
    }

    public function consent(Request $request)
    {
        $v = $request->validate(['consent_key' => 'required|string|max:100', 'version' => 'required|integer|min:1', 'accepted' => 'required|accepted']);
        $id = DB::table('client_consents')->insertGetId(['client_id' => $request->user()->id, ...$v,
            'accepted_at' => now(), 'ip_address' => $request->ip(), 'created_at' => now(), 'updated_at' => now()]);
        $this->audit($request, null, 'clinical.consent_accepted', ['consent_id' => $id, 'key' => $v['consent_key'], 'version' => $v['version']]);
        return response()->json(['data' => DB::table('client_consents')->find($id)], 201);
    }

    public function consents(Request $request, int $client)
    {
        $this->ensureClinicalAccess($request, $client);
        $this->audit($request, null, 'clinical.consents_accessed', ['client_id' => $client]);
        return response()->json(['data' => DB::table('client_consents')->where('client_id', $client)->orderByDesc('accepted_at')->get()]);
    }

    public function followUps(Request $request, int $client)
    {
        $counselorId = $request->user()->canAccessAdmin() ? null : $this->assignedCounselorId($request);
        $this->ensureClinicalAccess($request, $client, $counselorId);
        if ($request->isMethod('get')) {
            $query = DB::table('client_follow_up_tasks')->where('client_id', $client)->orderBy('due_date');
            if ($counselorId) $query->where('counselor_id', $counselorId);
            return response()->json(['data' => $query->paginate(30)]);
        }
        $v = $request->validate(['title' => 'required|string|max:180', 'details' => 'nullable|string|max:5000',
            'due_date' => 'nullable|date_format:Y-m-d|after_or_equal:today', 'booking_id' => 'nullable|integer|exists:bookings,id',
            'counselor_id' => 'nullable|integer|exists:counselors,id']);
        $counselorId ??= (int) ($v['counselor_id'] ?? DB::table('client_clinical_records')->where('client_id', $client)->value('primary_counselor_id'));
        abort_unless($counselorId > 0, 422, 'A counselor must be assigned to the client.');
        $id = DB::table('client_follow_up_tasks')->insertGetId(['client_id' => $client, 'counselor_id' => $counselorId,
            'created_by' => $request->user()->id, ...$v, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit($request, null, 'clinical.follow_up_created', ['task_id' => $id, 'client_id' => $client]);
        return response()->json(['data' => DB::table('client_follow_up_tasks')->find($id)], 201);
    }

    public function completeFollowUp(Request $request, int $task)
    {
        $row = DB::table('client_follow_up_tasks')->find($task);
        abort_unless($row, 404);
        $this->ensureClinicalAccess($request, (int) $row->client_id, (int) $row->counselor_id);
        DB::table('client_follow_up_tasks')->where('id', $task)->update(['completed_at' => now(), 'updated_at' => now()]);
        $this->audit($request, null, 'clinical.follow_up_completed', ['task_id' => $task]);
        return response()->noContent();
    }

    public function upload(Request $request, int $client)
    {
        $this->ensureClinicalAccess($request, $client);
        $v = $request->validate(['file' => 'required|file|max:10240|mimes:pdf,jpg,jpeg,png,doc,docx']);
        $file = $v['file'];
        $path = $file->store('clinical/'.$client, 'local');
        $id = DB::table('client_private_attachments')->insertGetId(['client_id' => $client, 'uploaded_by' => $request->user()->id,
            'disk' => 'local', 'path' => $path, 'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream', 'size_bytes' => $file->getSize(), 'created_at' => now(), 'updated_at' => now()]);
        $this->audit($request, null, 'clinical.attachment_uploaded', ['attachment_id' => $id, 'client_id' => $client]);
        return response()->json(['data' => DB::table('client_private_attachments')->where('id', $id)->first([
            'id', 'client_id', 'uploaded_by', 'original_name', 'mime_type', 'size_bytes', 'created_at',
        ])], 201);
    }

    public function attachments(Request $request, int $client)
    {
        $this->ensureClinicalAccess($request, $client);
        $this->audit($request, null, 'clinical.attachments_accessed', ['client_id' => $client]);
        return response()->json(['data' => DB::table('client_private_attachments')->where('client_id', $client)
            ->select('id', 'client_id', 'uploaded_by', 'original_name', 'mime_type', 'size_bytes', 'created_at')->orderByDesc('id')->paginate(30)]);
    }

    public function download(Request $request, int $attachment)
    {
        $row = DB::table('client_private_attachments')->find($attachment);
        abort_unless($row, 404);
        $this->ensureClinicalAccess($request, (int) $row->client_id);
        abort_unless(Storage::disk($row->disk)->exists($row->path), 404);
        $this->audit($request, null, 'clinical.attachment_downloaded', ['attachment_id' => $attachment]);
        return Storage::disk($row->disk)->download($row->path, $row->original_name, ['Content-Type' => $row->mime_type]);
    }

    public function notes(Request $request, int $booking)
    {
        $row = DB::table('bookings')->find($booking);
        abort_unless($row, 404);
        $this->ensureClinicalAccess($request, (int) $row->user_id, (int) $row->counselor_id);
        if ($request->isMethod('get')) {
            $this->audit($request, $booking, 'clinical.session_notes_accessed', []);
            return response()->json(['data' => DB::table('counselor_session_notes')->where('booking_id', $booking)->orderByDesc('id')->get()]);
        }
        $v = $request->validate(['body' => 'required|string|max:20000', 'status' => 'sometimes|in:draft,finalized',
            'outcomes' => 'nullable|array|max:100', 'outcomes.*' => 'string|max:1000',
            'follow_up_recommendations' => 'nullable|string|max:10000', 'treatment_progress' => 'nullable|string|max:10000']);
        $status = $v['status'] ?? 'draft';
        $id = DB::table('counselor_session_notes')->insertGetId(['booking_id' => $booking, 'counselor_id' => $row->counselor_id,
            'author_id' => $request->user()->id, 'body' => $v['body'], 'status' => $status,
            'outcomes' => isset($v['outcomes']) ? json_encode($v['outcomes']) : null,
            'follow_up_recommendations' => $v['follow_up_recommendations'] ?? null, 'treatment_progress' => $v['treatment_progress'] ?? null,
            'finalized_at' => $status === 'finalized' ? now() : null, 'created_at' => now(), 'updated_at' => now()]);
        $this->snapshotNote($request, $id);
        $this->audit($request, $booking, 'clinical.session_note_created', ['note_id' => $id, 'status' => $status]);
        return response()->json(['data' => DB::table('counselor_session_notes')->find($id)], 201);
    }

    public function updateNote(Request $request, int $note)
    {
        $current = DB::table('counselor_session_notes')->find($note);
        abort_unless($current, 404);
        $this->ensureClinicalAccess($request, (int) DB::table('bookings')->where('id', $current->booking_id)->value('user_id'), (int) $current->counselor_id);
        abort_unless($current->status === 'draft', 409, 'Finalized notes are immutable.');
        $v = $request->validate(['body' => 'sometimes|required|string|max:20000', 'status' => 'sometimes|in:draft,finalized',
            'outcomes' => 'nullable|array|max:100', 'outcomes.*' => 'string|max:1000',
            'follow_up_recommendations' => 'nullable|string|max:10000', 'treatment_progress' => 'nullable|string|max:10000']);
        $status = $v['status'] ?? $current->status;
        DB::table('counselor_session_notes')->where('id', $note)->update([...$v,
            'outcomes' => array_key_exists('outcomes', $v) ? json_encode($v['outcomes']) : $current->outcomes,
            'status' => $status, 'finalized_at' => $status === 'finalized' ? now() : null, 'updated_at' => now()]);
        $this->snapshotNote($request, $note);
        $this->audit($request, (int) $current->booking_id, 'clinical.session_note_updated', ['note_id' => $note, 'status' => $status]);
        return response()->json(['data' => DB::table('counselor_session_notes')->find($note)]);
    }

    public function noteVersions(Request $request, int $note)
    {
        $current = DB::table('counselor_session_notes')->find($note);
        abort_unless($current, 404);
        $booking = DB::table('bookings')->find($current->booking_id);
        abort_unless($booking, 404);
        $this->ensureClinicalAccess($request, (int) $booking->user_id, (int) $current->counselor_id);
        $versions = DB::table('counselor_session_note_versions as v')->join('users as u', 'u.id', '=', 'v.author_id')
            ->where('v.note_id', $note)->select('v.id', 'v.note_id', 'v.author_id', 'u.name as author_name', 'v.created_at')
            ->orderBy('v.id')->get()->values()->map(fn($row, $index) => [...(array)$row, 'version' => $index + 1]);
        $this->audit($request, (int) $booking->id, 'clinical.session_note_versions_accessed', ['note_id' => $note]);
        return response()->json(['data' => $versions]);
    }

    public function noteVersion(Request $request, int $note, int $version)
    {
        abort_unless($version > 0, 404);
        $current = DB::table('counselor_session_notes')->find($note);
        abort_unless($current, 404);
        $booking = DB::table('bookings')->find($current->booking_id);
        abort_unless($booking, 404);
        $this->ensureClinicalAccess($request, (int) $booking->user_id, (int) $current->counselor_id);
        $snapshot = DB::table('counselor_session_note_versions as v')->join('users as u', 'u.id', '=', 'v.author_id')
            ->where('v.note_id', $note)->orderBy('v.id')->skip($version - 1)->take(1)
            ->select('v.id', 'v.note_id', 'v.author_id', 'u.name as author_name', 'v.snapshot', 'v.created_at')->first();
        abort_unless($snapshot, 404);
        $decoded = json_decode($snapshot->snapshot, true) ?: [];
        $previous = DB::table('counselor_session_note_versions')->where('note_id', $note)->where('id', '<', $snapshot->id)->orderByDesc('id')->value('snapshot');
        $prior = $previous ? (json_decode($previous, true) ?: []) : [];
        $snapshot->version = $version;
        $snapshot->snapshot = $decoded;
        $snapshot->changed_fields = array_values(array_filter(array_unique([...array_keys($prior), ...array_keys($decoded)]), fn($key) => ($prior[$key] ?? null) !== ($decoded[$key] ?? null)));
        $this->audit($request, (int) $booking->id, 'clinical.session_note_version_accessed', ['note_id' => $note, 'version' => $version]);
        return response()->json(['data' => $snapshot]);
    }

    private function snapshotNote(Request $request, int $noteId): void
    {
        $note = DB::table('counselor_session_notes')->find($noteId);
        DB::table('counselor_session_note_versions')->insert(['note_id' => $noteId, 'author_id' => $request->user()->id,
            'snapshot' => json_encode(['body' => $note->body, 'status' => $note->status, 'outcomes' => $note->outcomes,
                'follow_up_recommendations' => $note->follow_up_recommendations, 'treatment_progress' => $note->treatment_progress]),
            'created_at' => now(), 'updated_at' => now()]);
    }

    private function audit(Request $request, ?int $booking, string $action, array $details): void
    {
        DB::table('booking_audit_events')->insert(['actor_id' => $request->user()->id, 'booking_id' => $booking,
            'action' => $action, 'details' => json_encode($details), 'created_at' => now(), 'updated_at' => now()]);
    }
}
