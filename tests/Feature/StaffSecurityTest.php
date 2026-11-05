<?php

namespace Tests\Feature;

use App\Models\StaffAuditLog;
use App\Models\User;
use App\Services\DiditReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class StaffSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role = 'reviewer', bool $mfa = true, bool $active = true): User
    {
        $user = User::factory()->create();
        $user->forceFill(['public_id' => (string) Str::uuid(), 'role' => $role, 'is_active' => $active, 'two_factor_confirmed_at' => $mfa ? now() : null])->save();

        return $user;
    }

    private function configure(): void
    {
        config(['tradexa.supabase_url' => 'https://example.supabase.co', 'tradexa.service_key' => 'test-only-key', 'tradexa.didit_key' => 'test-only-didit', 'tradexa.didit_environment' => 'production', 'tradexa.didit_workflow' => 'workflow1']);
    }

    private function sample(): array
    {
        return ['user_id' => '11111111-1111-1111-1111-111111111111', 'session_id' => 'session1234', 'workflow_id' => 'workflow1', 'status' => 'verified', 'admin_decision' => 'pending', 'updated_at' => '2026-11-05T10:00:00+00:00', 'valid_until' => '2027-11-05T10:00:00+00:00'];
    }

    public function test_public_registration_is_disabled(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [])->assertNotFound();
    }

    public function test_inactive_staff_cannot_access_records(): void
    {
        $this->actingAs($this->staff(active: false))->get('/users')->assertForbidden();
    }

    public function test_active_staff_can_access_dashboard_without_authenticator_setup(): void
    {
        $this->configure();
        Http::preventStrayRequests();
        Http::fake(['https://example.supabase.co/rest/v1/rpc/admin_monitoring_summary' => Http::response(['users' => 1]), 'https://example.supabase.co/rest/v1/provider_verification_sessions*' => Http::response([])]);
        $this->actingAs($this->staff(mfa: false))->get('/')->assertOk()->assertSee('Dashboard')->assertDontSee('Staff security');
        $this->get('/security')->assertRedirect('/');
    }

    public function test_auditors_cannot_view_documents_or_decide(): void
    {
        $this->actingAs($this->staff('auditor'))->withSession(['auth.password_confirmed_at' => time()]);
        $id = $this->sample()['user_id'];
        $this->get('/reviews/'.$id)->assertForbidden();
        $this->get('/reviews/'.$id.'/media/front')->assertForbidden();
        $this->post('/reviews/'.$id, [])->assertForbidden();
    }

    public function test_staff_login_uses_email_and_password_without_authenticator_challenge(): void
    {
        $password = 'Only-in-tests!2026';
        $user = $this->staff();
        $user->forceFill(['password' => $password, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP')])->save();
        $this->post('/login', ['email' => $user->email, 'password' => $password])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_old_preview_redirects_to_the_authenticated_dashboard(): void
    {
        $this->get('/preview')->assertRedirect('/');
        $this->get('/')->assertRedirect('/login');
    }

    public function test_dashboard_renders_only_shared_backend_records(): void
    {
        $this->configure();
        Http::preventStrayRequests();
        $summary = ['users' => 7, 'awaiting_review' => 1, 'approved' => 2, 'support_open' => 0];
        Http::fake(['https://example.supabase.co/rest/v1/rpc/admin_monitoring_summary' => Http::response($summary), 'https://example.supabase.co/rest/v1/provider_verification_sessions*' => Http::response([])]);
        $this->actingAs($this->staff())->get('/')->assertOk()->assertViewHas('summary', $summary)->assertSee('No cases in this view')->assertDontSee('Sample account')->assertDontSee('Design preview')->assertDontSee('estimate-amount');
    }

    public function test_dashboard_backend_failure_has_no_sample_substitution(): void
    {
        $this->configure();
        Http::preventStrayRequests();
        Http::fake(['https://example.supabase.co/rest/v1/rpc/admin_monitoring_summary' => Http::response([], 503)]);
        $this->actingAs($this->staff())->get('/')->assertOk()->assertViewHas('summary', null)->assertSee('Some platform data is unavailable.')->assertDontSee('Sample account');
    }

    public function test_approval_is_blocked_when_face_screening_fails(): void
    {
        $this->configure();
        $case = $this->sample();
        $decision = ['session_id' => $case['session_id'], 'vendor_data' => $case['user_id'], 'workflow_id' => 'workflow1', 'session_kind' => 'user', 'status' => 'Approved', 'id_verifications' => [['status' => 'Approved', 'front_image' => 'https://media.example/front.jpg']], 'liveness_checks' => [['status' => 'Approved']], 'face_matches' => [['status' => 'Declined']]];
        Http::fake(['https://example.supabase.co/rest/v1/provider_verification_sessions*' => Http::response([$case]), 'https://verification.didit.me/*' => Http::response($decision)]);
        $this->actingAs($this->staff())->withSession(['auth.password_confirmed_at' => time()])->from('/reviews/'.$case['user_id'])->post('/reviews/'.$case['user_id'], ['session_id' => $case['session_id'], 'expected_updated_at' => $case['updated_at'], 'decision' => 'approved', 'reason' => 'Review completed using the evidence.'])->assertRedirect('/reviews/'.$case['user_id'])->assertSessionHasErrors('decision');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'rpc/review_identity'));
    }

    public function test_successful_review_uses_server_staff_identity_and_is_audited(): void
    {
        $this->configure();
        $case = $this->sample();
        $staff = $this->staff();
        StaffAuditLog::create(['actor_id' => $staff->public_id, 'action' => 'document.viewed', 'subject_id' => $case['user_id'], 'details' => ['kind' => 'front', 'session_id' => $case['session_id']]]);
        $decision = ['session_id' => $case['session_id'], 'vendor_data' => $case['user_id'], 'workflow_id' => 'workflow1', 'session_kind' => 'user', 'status' => 'Approved', 'id_verifications' => [['status' => 'Approved', 'front_image' => 'https://media.example/front.jpg']], 'liveness_checks' => [['status' => 'Approved']], 'face_matches' => [['status' => 'Approved']]];
        Http::fake(['https://example.supabase.co/rest/v1/provider_verification_sessions*' => Http::response([$case]), 'https://verification.didit.me/*' => Http::response($decision), 'https://example.supabase.co/rest/v1/rpc/review_identity' => Http::response('verified')]);
        $this->actingAs($staff)->withSession(['auth.password_confirmed_at' => time()])->post('/reviews/'.$case['user_id'], ['session_id' => $case['session_id'], 'expected_updated_at' => $case['updated_at'], 'decision' => 'approved', 'reason' => 'Document details and face evidence checked.', 'actor' => 'forged-client-actor'])->assertRedirect('/reviews');
        Http::assertSent(fn ($request) => str_contains($request->url(), 'rpc/review_identity') && $request['p_actor'] === $staff->public_id);
        $this->assertDatabaseHas('staff_audit_logs', ['actor_id' => $staff->public_id, 'action' => 'review.approved']);
    }

    public function test_stale_case_is_not_reviewed(): void
    {
        $this->configure();
        $case = $this->sample();
        Http::fake(['https://example.supabase.co/rest/v1/provider_verification_sessions*' => Http::response([$case])]);
        $this->actingAs($this->staff())->withSession(['auth.password_confirmed_at' => time()])->post('/reviews/'.$case['user_id'], ['session_id' => 'different-session', 'expected_updated_at' => $case['updated_at'], 'decision' => 'approved', 'reason' => 'The evidence has been reviewed.'])->assertSessionHasErrors('decision');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'rpc/review_identity'));
    }

    public function test_sensitive_pages_send_no_store_and_frame_protection(): void
    {
        $this->get('/login')->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Frame-Options', 'DENY')->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_document_proxy_rejects_untrusted_media_hosts(): void
    {
        $this->configure();
        $case = $this->sample();
        $decision = ['session_id' => $case['session_id'], 'vendor_data' => $case['user_id'], 'workflow_id' => 'workflow1', 'session_kind' => 'user', 'status' => 'Approved', 'id_verifications' => [['front_image' => 'https://untrusted.invalid/private.png']]];
        Http::fake(['https://verification.didit.me/*' => Http::response($decision)]);
        try {
            app(DiditReviewService::class)->media($case, 'front');
            $this->fail('Untrusted media host was accepted.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not enabled', $e->getMessage());
        }
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'untrusted.invalid'));
    }

    public function test_short_review_reasons_are_rejected_before_provider_calls(): void
    {
        Http::fake();
        $this->actingAs($this->staff())->withSession(['auth.password_confirmed_at' => time()])->post('/reviews/'.$this->sample()['user_id'], ['session_id' => 'session1234', 'expected_updated_at' => '2026-11-05T10:00:00Z', 'decision' => 'approved', 'reason' => 'yes'])->assertSessionHasErrors('reason');
        Http::assertNothingSent();
    }
}
