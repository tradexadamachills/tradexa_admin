<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ScreenDocumentsTest extends TestCase
{
    private const USER = '11111111-1111-1111-1111-111111111111';
    private const SESSION = '22222222-2222-2222-2222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'tradexa.supabase_url' => 'https://example.supabase.co',
            'tradexa.service_key' => 'test-only-key',
            'tradexa.document_model_url' => 'http://127.0.0.1:8002',
            'tradexa.document_model_secret' => 'test-only-secret',
            'tradexa.document_encryption_key' => str_repeat('ab', 32),
        ]);
    }

    private function fakeBackend(string $classification, int $modelStatus = 200): void
    {
        Http::fake([
            '*/rest/v1/document_intake?select*' => Http::response([[
                'user_id' => self::USER, 'session_id' => self::SESSION, 'document_type' => 'national_id',
                'front_path' => self::USER.'/'.self::SESSION.'/front', 'back_path' => null, 'created_at' => now()->toIso8601String(),
            ]]),
            '*/storage/v1/object/review-intake/*' => Http::response("\xFF\xD8\xFFfake-jpeg-bytes"),
            '127.0.0.1:8002/screen' => $modelStatus === 200
                ? Http::response(['model' => 'easyocr-document-v1', 'classification' => $classification,
                    'front' => ['reason' => $classification === 'document_like' ? null : 'no_portrait_found'], 'back' => null])
                : Http::response('', $modelStatus),
            '*/rest/v1/rpc/finish_document_submission' => Http::response(
                json_encode($classification === 'document_like' ? 'manual_review' : 'rejected'), 200, ['Content-Type' => 'application/json']),
            '*/storage/v1/object/review-documents/*' => Http::response([], 200),
            '*/rest/v1/document_review_submissions*' => Http::response([], 204),
            '*/rest/v1/document_intake?user_id*' => Http::response([], 204),
        ]);
    }

    private function sent(callable $match): bool
    {
        return Http::recorded()->contains(fn ($pair) => $match($pair[0]));
    }

    public function test_accepted_document_is_encrypted_stored_and_queued_for_admin_review(): void
    {
        $this->fakeBackend('document_like');
        $this->artisan('documents:screen')->assertSuccessful();
        $this->assertTrue($this->sent(fn (Request $r) => str_contains($r->url(), '/review-documents/'.self::USER.'/'.self::SESSION.'/front.enc')
            && str_starts_with($r->body(), 'TDOC1')), 'encrypted copy stored');
        $this->assertTrue($this->sent(fn (Request $r) => str_contains($r->url(), 'rpc/finish_document_submission')
            && ($r->data()['p_front'] ?? null) === self::USER.'/'.self::SESSION.'/front.enc'), 'submission finished');
        $this->assertTrue($this->sent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), 'review-intake')), 'intake removed');
    }

    public function test_rejected_document_is_never_stored_for_review(): void
    {
        $this->fakeBackend('not_document');
        $this->artisan('documents:screen')->assertSuccessful();
        $this->assertFalse($this->sent(fn (Request $r) => str_contains($r->url(), '/review-documents/')), 'nothing stored');
        $this->assertTrue($this->sent(fn (Request $r) => str_contains($r->url(), 'rpc/finish_document_submission')
            && array_key_exists('p_front', $r->data()) && $r->data()['p_front'] === null), 'finished as rejected');
    }

    public function test_job_stays_queued_when_the_screening_service_is_down(): void
    {
        $this->fakeBackend('document_like', 500);
        $this->artisan('documents:screen')->assertSuccessful();
        $this->assertFalse($this->sent(fn (Request $r) => str_contains($r->url(), 'rpc/finish_document_submission')), 'not finished');
        $this->assertFalse($this->sent(fn (Request $r) => $r->method() === 'DELETE'), 'photos kept for retry');
    }
}
