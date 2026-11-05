<?php

namespace App\Http\Controllers;

use App\Models\StaffAuditLog;
use App\Services\DocumentReviewService;
use App\Services\SupabaseGateway;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function __construct(private SupabaseGateway $backend, private DocumentReviewService $provider) {}

    private function audit(Request $request, string $action, ?string $subject = null, array $details = []): void
    {
        StaffAuditLog::create(['actor_id' => $request->user()?->public_id, 'action' => $action, 'subject_id' => $subject, 'details' => $details]);
    }

    public function dashboard()
    {
        $error = null;
        $summary = null;
        $cases = [];
        $analytics = null;
        try {
            $summary = $this->backend->rpc('admin_monitoring_summary');
            $analytics = $this->backend->rpc('admin_analytics', ['p_days' => 30]);
            $cases = $this->backend->records('document_review_submissions', ['select' => 'user_id,session_id,status,admin_decision,admin_seen_at,created_at,updated_at', 'order' => 'created_at.desc', 'limit' => 8]);
        } catch (\RuntimeException $e) {
            $error = $e->getMessage();
        }

        return view('admin.dashboard', compact('summary', 'cases', 'error', 'analytics') + ['preview' => false]);
    }

    public function users(Request $request)
    {
        $request->validate(['page' => 'nullable|integer|min:1|max:10000', 'q' => 'nullable|string|max:100']);
        $page = (int) $request->input('page', 1);
        $error = null;
        $rows = [];
        try {
            $rows = $this->backend->records('profiles', ['select' => 'id,full_name,email,created_at', 'order' => 'created_at.desc', 'limit' => 50, 'offset' => ($page - 1) * 50]);
        } catch (\RuntimeException $e) {
            $error = $e->getMessage();
        }
        if ($request->filled('q')) {
            $q = mb_strtolower($request->string('q'));
            $rows = array_values(array_filter($rows, fn ($row) => str_contains(mb_strtolower(($row['full_name'] ?? '').' '.($row['email'] ?? '')), $q)));
        }

        return view('admin.users', compact('rows', 'error', 'page') + ['preview' => false]);
    }

    public function reviews(Request $request)
    {
        $request->validate(['page' => 'nullable|integer|min:1|max:10000', 'status' => 'nullable|in:all,pending,approved,rejected,resubmit']);
        $page = (int) $request->input('page', 1);
        $error = null;
        $cases = [];
        $status = $request->input('status', 'pending');
        try {
            $query = ['select' => 'user_id,session_id,status,admin_decision,admin_seen_at,valid_until,created_at,updated_at', 'order' => 'created_at.asc', 'limit' => 50, 'offset' => ($page - 1) * 50];
            if ($status !== 'all') {
                $query['admin_decision'] = 'eq.'.$status;
                if ($status === 'pending') { $query['status'] = 'eq.document_like'; }
            }$cases = $this->backend->records('document_review_submissions', $query);
        } catch (\RuntimeException $e) {
            $error = $e->getMessage();
        }

        return view('admin.reviews', compact('cases', 'error', 'page', 'status') + ['preview' => false]);
    }

    public function show(Request $request, string $user)
    {
        abort_unless(in_array($request->user()->role, ['admin', 'reviewer'], true), 403);
        $case = $this->backend->case($user);
        $decision = null;
        $providerError = null;
        $profile = null;
        try {
            $decision = $this->provider->decision($case);
        } catch (\RuntimeException $e) {
            $providerError = $e->getMessage();
        }
        $profile = $this->backend->records('profiles', ['id' => 'eq.'.$user, 'select' => 'full_name,email,created_at', 'limit' => 1])[0] ?? null;
        $this->audit($request, 'review.opened', $user, ['session_id' => $case['session_id']]);
        try { $this->backend->markSeen($user); } catch (\Throwable) { /* highlight only */ }
        $screeningPass = $decision && $this->provider->passes($decision);

        return view('admin.case', compact('case', 'decision', 'profile', 'providerError', 'screeningPass') + ['preview' => false]);
    }

    public function decide(Request $request, string $user)
    {
        abort_unless(in_array($request->user()->role, ['admin', 'reviewer'], true), 403);
        $data = $request->validate(['session_id' => 'required|string|max:80', 'decision' => 'required|in:approved,rejected,resubmit', 'reason' => 'required|string|min:10|max:2000', 'expected_updated_at' => 'required|date']);
        try {
            $case = $this->backend->case($user);
            if ($case['session_id'] !== $data['session_id'] || $case['updated_at'] !== $data['expected_updated_at']) {
                throw new \RuntimeException('The case changed. Reload and review the new evidence.');
            }
            if ($data['decision'] === 'approved') {
                $evidence = $this->provider->decision($case);
                if ($case['status'] !== 'document_like' || ! $this->provider->passes($evidence)) {
                    throw new \RuntimeException('Approval requires a recognized document and an administrator review.');
                }
                $document = ['front_image' => $case['front_path'] ?? null, 'back_image' => $case['back_path'] ?? null];
                $required = ['front'];
                if (! empty($document['back_image'])) {
                    $required[] = 'back';
                }
                if (empty($document['front_image'])) {
                    throw new \RuntimeException('Document evidence is missing. Approval is disabled.');
                }
                foreach ($required as $kind) {
                    $viewed = StaffAuditLog::where('actor_id', $request->user()->public_id)->where('subject_id', $user)->where('action', 'document.viewed')->where('details->session_id', $case['session_id'])->where('details->kind', $kind)->exists();
                    if (! $viewed) {
                        throw new \RuntimeException('Open the front and any available back document image before approving this case.');
                    }
                }
            }
            $this->backend->rpc('review_document', ['p_user' => $user, 'p_session' => $data['session_id'], 'p_actor' => $request->user()->public_id, 'p_decision' => $data['decision'], 'p_reason' => $data['reason'], 'p_expected_updated_at' => $data['expected_updated_at']]);
            $this->audit($request, 'review.'.$data['decision'], $user, ['session_id' => $data['session_id']]);

            return redirect()->route('reviews.index')->with('status', 'Review decision recorded.');
        } catch (\RuntimeException $e) {
            return back()->withErrors(['decision' => $e->getMessage()]);
        }
    }

    public function media(Request $request, string $user, string $kind)
    {
        abort_unless(in_array($request->user()->role, ['admin', 'reviewer'], true), 403);
        try {
            $case = $this->backend->case($user);
            $media = $this->provider->media($case, $kind);
        } catch (\RuntimeException) {
            abort(503, 'The document image is not available for secure review.');
        }
        $this->audit($request, 'document.viewed', $user, ['kind' => $kind, 'session_id' => $case['session_id']]);

        return response($media['bytes'])->header('Content-Type', $media['type'])->header('Content-Disposition', 'inline')->header('Cache-Control', 'private, no-store');
    }

    public function auditLog()
    {
        $central = [];
        $error = null;
        try {
            $central = $this->backend->records('verification_admin_audit', ['select' => 'user_id,session_id,actor,decision,reason,created_at', 'order' => 'created_at.desc', 'limit' => 50]);
        } catch (\RuntimeException $e) {
            $error = $e->getMessage();
        }

        return view('admin.audit', ['rows' => StaffAuditLog::orderByDesc('id')->paginate(50), 'central' => $central, 'error' => $error, 'preview' => false]);
    }

    public function support()
    {
        $error = null;
        $rows = [];
        try {
            $rows = $this->backend->records('support_tickets', ['select' => 'id,user_id,topic,message,status,created_at', 'order' => 'created_at.desc', 'limit' => 100]);
        } catch (\RuntimeException $e) {
            $error = $e->getMessage();
        }

        return view('admin.support', compact('rows', 'error') + ['preview' => false]);
    }

    public function resolve(Request $request, string $ticket)
    {
        abort_unless($request->user()->role === 'admin', 403);
        try {
            $this->backend->resolveTicket($ticket);
            $this->audit($request, 'support.resolved', null, ['ticket_id' => $ticket]);

            return back()->with('status', 'Support request resolved.');
        } catch (\RuntimeException $e) {
            return back()->withErrors(['support' => $e->getMessage()]);
        }
    }

    public function integrations()
    {
        return view('admin.integrations', ['preview' => false, 'configured' => $this->backend->configured()]);
    }
}
