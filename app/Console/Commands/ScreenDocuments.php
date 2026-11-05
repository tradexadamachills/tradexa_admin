<?php

namespace App\Console\Commands;

use App\Services\DocumentReviewService;
use App\Services\DocumentScreeningService;
use App\Services\SupabaseGateway;
use Illuminate\Console\Command;

class ScreenDocuments extends Command
{
    protected $signature = 'documents:screen {--watch : keep running and check every 10 seconds}';
    protected $description = 'Screens identity documents that users submitted from the apps, then queues accepted ones for admin review';

    public function handle(SupabaseGateway $backend, DocumentScreeningService $screening, DocumentReviewService $documents): int
    {
        do {
            try {
                $this->runOnce($backend, $screening, $documents);
            } catch (\Throwable $e) {
                $this->error('Run failed: '.$e->getMessage());
            }
            if ($this->option('watch')) { sleep(10); }
        } while ($this->option('watch'));

        return self::SUCCESS;
    }

    private function runOnce(SupabaseGateway $backend, DocumentScreeningService $screening, DocumentReviewService $documents): void
    {
        foreach ($backend->records('document_intake', ['select' => '*', 'order' => 'created_at.asc', 'limit' => 20]) as $job) {
            $user = $job['user_id'];
            $session = $job['session_id'];
            try {
                $front = $backend->intakeObject($job['front_path']);
                $back = $job['back_path'] ? $backend->intakeObject($job['back_path']) : null;
                $vision = $job['vision_verdict'] ?? null;
                $idLike = is_array($vision) && in_array($vision['category'] ?? null, ['id_card', 'passport', 'driving_licence'], true)
                    && (float) ($vision['confidence'] ?? 0) >= 0.6 && ($vision['readable'] ?? false) === true;
                // The instant vision check already decided "this is an ID". Always keep the
                // document for the admin; the local OCR scan only runs when that check was unavailable.
                $result = $idLike
                    ? ['model' => 'vision-document-v1', 'classification' => 'document_like', 'document_type' => $job['document_type'],
                        'vision' => ['category' => $vision['category'], 'confidence' => $vision['confidence']],
                        'limitation' => 'Document-type screening only; authenticity and ownership are not established.']
                    : $screening->screenBytes($front, $back, $job['document_type']);
                $paths = ['front' => null, 'back' => null];
                if ($documents->passes($result)) {
                    $paths = ['front' => "$user/$session/front.enc", 'back' => $back !== null ? "$user/$session/back.enc" : null];
                    $backend->setDocumentPaths($user, $session, $paths);
                    foreach (['front' => $front, 'back' => $back] as $kind => $bytes) {
                        if ($bytes === null) { continue; }
                        $mime = str_starts_with($bytes, "\x89PNG") ? 'image/png' : 'image/jpeg';
                        $backend->putDocument($paths[$kind], $documents->encrypt($bytes, $mime, $paths[$kind]));
                    }
                }
                $status = $backend->rpc('finish_document_submission', ['p_user' => $user, 'p_session' => $session, 'p_result' => $result, 'p_front' => $paths['front'], 'p_back' => $paths['back']]);
                $backend->clearIntake($user, [$job['front_path'], $job['back_path']]);
                $this->info(substr($user, 0, 8).': '.$status);
            } catch (\Throwable $e) {
                // The screening service may be down; the job stays queued and is retried.
                $this->warn(substr($user, 0, 8).': '.$e->getMessage());
            }
        }
    }
}
