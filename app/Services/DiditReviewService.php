<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class DiditReviewService
{
    public function decision(array $case): array
    {
        if (! config('tradexa.didit_key') || config('tradexa.didit_environment') !== 'production') {
            throw new \RuntimeException('The live document provider is not configured.');
        }
        $id = $case['session_id'] ?? '';
        if (! preg_match('/^[a-zA-Z0-9-]{8,80}$/', $id)) {
            throw new \RuntimeException('No provider session is available.');
        }
        $response = Http::withHeaders(['x-api-key' => config('tradexa.didit_key')])->acceptJson()->timeout(15)->get('https://verification.didit.me/v3/session/'.rawurlencode($id).'/decision/');
        if (! $response->successful()) {
            throw new \RuntimeException('The provider evidence is temporarily unavailable.');
        }
        $data = $response->json();
        if (! is_array($data) || ($data['session_id'] ?? null) !== $case['session_id'] || ($data['vendor_data'] ?? null) !== $case['user_id'] || ($data['workflow_id'] ?? null) !== $case['workflow_id'] || ($data['session_kind'] ?? null) !== 'user' || ($data['sandbox_scenario'] ?? null) !== null || ($data['environment'] ?? 'live') !== 'live') {
            throw new \RuntimeException('Provider ownership or environment validation failed.');
        }

        return $data;
    }

    public function passes(array $decision): bool
    {
        if (! is_string($decision['status'] ?? null) || strtolower($decision['status']) !== 'approved' || ($decision['status_override'] ?? null) !== null) {
            return false;
        }
        foreach (['id_verifications', 'liveness_checks', 'face_matches'] as $key) {
            $rows = $decision[$key] ?? null;
            if (! is_array($rows) || ! count($rows)) {
                return false;
            }foreach ($rows as $row) {
                if (! is_array($row) || ! is_string($row['status'] ?? null) || strtolower($row['status']) !== 'approved') {
                    return false;
                }
            }
        }

        return true;
    }

    public function media(array $case, string $kind): array
    {
        $data = $this->decision($case);
        $url = match ($kind) {
            'front' => $data['id_verifications'][0]['front_image'] ?? null,'back' => $data['id_verifications'][0]['back_image'] ?? null,'portrait' => $data['id_verifications'][0]['portrait_image'] ?? null,'selfie' => $data['liveness_checks'][0]['reference_image'] ?? null,default => null
        };
        if (! is_string($url) || parse_url($url, PHP_URL_SCHEME) !== 'https' || ! in_array(parse_url($url, PHP_URL_HOST), config('tradexa.media_hosts'), true) || parse_url($url, PHP_URL_USER) || parse_url($url, PHP_URL_PORT)) {
            throw new \RuntimeException('This media host is not enabled for secure review.');
        }
        $response = Http::timeout(15)->withOptions(['allow_redirects' => false, 'stream' => true])->get($url);
        $type = explode(';', $response->header('Content-Type'))[0];
        if (! $response->successful() || ! in_array($type, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new \RuntimeException('A supported document image is not available.');
        }
        $bytes = $response->toPsrResponse()->getBody()->read(8 * 1024 * 1024 + 1);
        if (strlen($bytes) > 8 * 1024 * 1024) {
            throw new \RuntimeException('The image exceeds the review size limit.');
        }

        return ['bytes' => $bytes, 'type' => $type];
    }
}
