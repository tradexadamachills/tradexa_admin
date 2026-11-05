<?php
namespace App\Services;
class DocumentReviewService
{
    public function __construct(private SupabaseGateway $backend) {}
    public function decision(array $case): array
    {
        return $case['screening_result'] ?? [];
    }
    public function passes(array $decision): bool
    {
        return ($decision['model'] ?? null) === 'easyocr-document-v1' && ($decision['classification'] ?? null) === 'document_like';
    }
    private function key(): string
    {
        $hex = config('tradexa.document_encryption_key', '');
        if (! is_string($hex) || ! preg_match('/^[a-f0-9]{64}$/', $hex)) { throw new \RuntimeException('Private document encryption is not configured.'); }
        return hex2bin($hex);
    }
    public function encrypt(string $bytes, string $mime, string $path): string
    {
        $iv = random_bytes(12);
        $cipher = openssl_encrypt($bytes, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag, $path);
        if ($cipher === false) { throw new \RuntimeException('Could not secure document.'); }
        return 'TDOC1'.chr($mime === 'image/png' ? 1 : 0).$iv.$tag.$cipher;
    }
    public function media(array $case, string $kind): array
    {
        if (! in_array($kind, ['front', 'back'], true)) { throw new \RuntimeException('Unsupported document image.'); }
        $path = $case[$kind.'_path'] ?? null;
        $expected = $case['user_id'].'/'.$case['session_id'].'/'.$kind.'.enc';
        if ($path !== $expected || ! preg_match('/^[a-f0-9-]{36}\/[a-f0-9-]{36}\/(front|back)\.enc$/', $path)) { throw new \RuntimeException('Document path does not match this case.'); }
        $raw = $this->backend->document($path);
        if (strlen($raw) < 35 || substr($raw, 0, 5) !== 'TDOC1') { throw new \RuntimeException('Invalid encrypted document.'); }
        $bytes = openssl_decrypt(substr($raw, 34), 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, substr($raw, 6, 12), substr($raw, 18, 16), $path);
        if ($bytes === false) { throw new \RuntimeException('Could not decrypt document.'); }
        return ['bytes' => $bytes, 'type' => ord($raw[5]) === 1 ? 'image/png' : 'image/jpeg'];
    }
}
