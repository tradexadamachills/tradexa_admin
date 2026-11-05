<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class SupabaseGateway
{
    public function configured(): bool
    {
        return filled(config('tradexa.supabase_url')) && filled(config('tradexa.service_key'));
    }

    private function client(): PendingRequest
    {
        if (! $this->configured()) {
            throw new \RuntimeException('The shared backend is not configured.');
        }
        $url = rtrim(config('tradexa.supabase_url'), '/');
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' && ! app()->environment('testing')) {
            throw new \RuntimeException('HTTPS is required for the shared backend.');
        }

        return Http::baseUrl($url.'/rest/v1/')->withHeaders(['apikey' => config('tradexa.service_key'), 'Authorization' => 'Bearer '.config('tradexa.service_key')])->acceptJson()->timeout(15)->connectTimeout(5);
    }

    public function records(string $table, array $query = []): array
    {
        if (! in_array($table, ['profiles', 'provider_verification_sessions', 'verification_admin_audit', 'provider_verification_events', 'support_tickets', 'portfolio_snapshots', 'document_review_submissions', 'document_intake'], true)) {
            throw new \InvalidArgumentException('Table is not allowed.');
        }
        $response = $this->client()->get($table, $query);
        if (! $response->successful()) {
            throw new \RuntimeException('The shared backend could not return these records.');
        }

        return $response->json() ?? [];
    }

    public function rpc(string $name, array $body = []): mixed
    {
        if (! in_array($name, ['admin_monitoring_summary', 'review_identity', 'review_document', 'reserve_document_submission', 'finish_document_submission', 'delete_document_submission', 'admin_analytics'], true)) {
            throw new \InvalidArgumentException('Operation is not allowed.');
        }
        $response = $this->client()->post('rpc/'.$name, $body);
        if (! $response->successful()) {
            throw new \RuntimeException($response->status() === 409 ? 'The case changed or was already reviewed. Reload before deciding.' : 'The operation could not be completed. Check provider screening and reload the case.');
        }

        return $response->json();
    }

    /** Returns null when reserved, else 'already_submitted' | 'rate_limited' | 'error'. */
    public function reserveDocument(string $user, string $session, string $type): ?string
    {
        $response = $this->client()->post('rpc/reserve_document_submission', ['p_user' => $user, 'p_session' => $session, 'p_type' => $type]);
        if ($response->successful()) { return null; }
        $text = (string) $response->body();
        if (str_contains($text, 'document_already_submitted')) { return 'already_submitted'; }
        if (str_contains($text, 'document_rate_limited')) { return 'rate_limited'; }
        return 'error';
    }

    /** Marks a new submission as opened by staff (clears the "new" highlight). */
    public function markSeen(string $user): void
    {
        $this->client()->patch('document_review_submissions?user_id=eq.'.rawurlencode($user).'&admin_seen_at=is.null', ['admin_seen_at' => now()->toIso8601String()]);
    }

    public function case(string $userId): array
    {
        $rows = $this->records('document_review_submissions', ['user_id' => 'eq.'.$userId, 'select' => '*', 'limit' => 1]);
        if (! $rows) {
            throw new \RuntimeException('The verification case was not found.');
        }

        return $rows[0];
    }

    public function resolveTicket(string $id): void
    {
        $response = $this->client()->patch('support_tickets?id=eq.'.rawurlencode($id), ['status' => 'closed']);
        if (! $response->successful()) {
            throw new \RuntimeException('Could not update the support request.');
        }
    }

    public function customer(?string $token): ?array
    {
        if (! $token || ! $this->configured()) { return null; }
        $headers = ['apikey' => config('tradexa.service_key'), 'Authorization' => 'Bearer '.$token];
        $base = rtrim(config('tradexa.supabase_url'), '/');
        $response = Http::withHeaders($headers)->acceptJson()->timeout(10)->get($base.'/auth/v1/user');
        if (! $response->successful() || ! is_string($response->json('id'))) { return null; }
        $proof = Http::withHeaders($headers)->acceptJson()->timeout(10)->send('POST', $base.'/rest/v1/rpc/has_email_otp', ['json' => new \stdClass]);
        return $proof->successful() && $proof->json() === true ? $response->json() : null;
    }

    public function setDocumentPaths(string $user, string $session, array $paths): void
    {
        $response = $this->client()->patch('document_review_submissions?user_id=eq.'.rawurlencode($user).'&session_id=eq.'.rawurlencode($session).'&status=eq.processing', ['front_path' => $paths['front'], 'back_path' => $paths['back']]);
        if (! $response->successful()) { throw new \RuntimeException('Could not reserve private media paths.'); }
    }

    public function failDocument(string $user, string $session): void
    {
        $this->client()->patch('document_review_submissions?user_id=eq.'.rawurlencode($user).'&session_id=eq.'.rawurlencode($session).'&status=eq.processing', ['status' => 'provider_error', 'updated_at' => now()->toIso8601String()]);
    }

    private function storage(): PendingRequest
    {
        if (! $this->configured()) { throw new \RuntimeException('Document storage is not configured.'); }
        return Http::baseUrl(rtrim(config('tradexa.supabase_url'), '/').'/storage/v1/object/review-documents/')->withHeaders(['apikey' => config('tradexa.service_key'), 'Authorization' => 'Bearer '.config('tradexa.service_key')])->timeout(20);
    }

    private function intakeStorage(): PendingRequest
    {
        if (! $this->configured()) { throw new \RuntimeException('Document storage is not configured.'); }
        return Http::baseUrl(rtrim(config('tradexa.supabase_url'), '/').'/storage/v1/object/review-intake/')->withHeaders(['apikey' => config('tradexa.service_key'), 'Authorization' => 'Bearer '.config('tradexa.service_key')])->timeout(30);
    }

    public function intakeObject(string $path): string
    {
        $response = $this->intakeStorage()->get($path);
        if (! $response->successful()) { throw new \RuntimeException('Intake photo is unavailable.'); }
        return $response->body();
    }

    /** Removes the intake photos and the queue row once a submission has been processed. */
    public function clearIntake(string $user, array $paths): void
    {
        foreach (array_filter($paths) as $path) { $this->intakeStorage()->delete($path); }
        $this->client()->delete('document_intake?user_id=eq.'.rawurlencode($user));
    }

    public function putDocument(string $path, string $bytes): void
    {
        $response = $this->storage()->withBody($bytes, 'application/octet-stream')->post($path);
        if (! $response->successful()) { throw new \RuntimeException('Could not store the private document.'); }
    }

    public function document(string $path): string
    {
        $response = $this->storage()->get($path);
        if (! $response->successful()) { throw new \RuntimeException('Document evidence is unavailable.'); }
        return $response->body();
    }
}
