<?php
namespace App\Services;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
class DocumentScreeningService
{
    public function screen(UploadedFile $front, ?UploadedFile $back, string $type): array
    {
        return $this->screenBytes($front->getContent(), $back?->getContent(), $type);
    }

    public function screenBytes(string $front, ?string $back, string $type): array
    {
        $url = rtrim(config('tradexa.document_model_url'), '/');
        if (! config('tradexa.document_model_secret') || (parse_url($url, PHP_URL_SCHEME) !== 'https' && ! in_array(parse_url($url, PHP_URL_HOST), ['127.0.0.1', 'localhost'], true))) {
            throw new \RuntimeException('Document screening is not configured.');
        }
        $response = Http::withToken(config('tradexa.document_model_secret'))->acceptJson()->timeout(120)->post($url.'/screen', ['front' => base64_encode($front), 'back' => $back !== null ? base64_encode($back) : null, 'document_type' => $type]);
        $result = $response->json();
        if (! $response->successful() || ! is_array($result) || ($result['model'] ?? null) !== 'easyocr-document-v1' || ! in_array($result['classification'] ?? null, ['document_like', 'not_document'], true)) {
            throw new \RuntimeException('Document screening is unavailable.');
        }
        return $result;
    }
}
