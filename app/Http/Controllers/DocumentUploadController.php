<?php
namespace App\Http\Controllers;
use App\Services\DocumentReviewService;
use App\Services\DocumentScreeningService;
use App\Services\SupabaseGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
class DocumentUploadController extends Controller
{
    public function __construct(private SupabaseGateway $backend, private DocumentScreeningService $screening, private DocumentReviewService $documents) {}
    public function store(Request $request): JsonResponse
    {
        $user = $this->backend->customer($request->bearerToken());
        abort_unless($user, 401, 'Sign in and verify your email first.');
        $data = $request->validate(['document_type' => 'required|in:national_id,passport,driving_licence', 'consent' => 'required|accepted', 'front' => 'required|image|mimes:jpg,jpeg,png|max:2048', 'back' => 'nullable|image|mimes:jpg,jpeg,png|max:2048']);
        $backFile = $request->file('back');
        if ($backFile && hash_file('sha256', $request->file('front')->getRealPath()) === hash_file('sha256', $backFile->getRealPath())) {
            return response()->json(['message' => 'The front and back are the same picture. Upload the front of your ID in the first box and the back in the second (or leave the back empty).'], 422);
        }
        $session = (string) Str::uuid();
        try {
            $blocked = $this->backend->reserveDocument($user['id'], $session, $data['document_type']);
        } catch (\Throwable) {
            return response()->json(['message' => 'Document submission is temporarily unavailable. Please try again shortly.'], 503);
        }
        if ($blocked === 'already_submitted') {
            return response()->json(['message' => 'You already have a document awaiting review. Remove it first if you want to upload a different one.'], 409);
        }
        if ($blocked === 'rate_limited') {
            return response()->json(['message' => 'You have reached the limit of 5 uploads in 24 hours. Please try again tomorrow.'], 429);
        }
        if ($blocked !== null) {
            return response()->json(['message' => 'Document submission is temporarily unavailable. Please try again shortly.'], 503);
        }
        try {
            $front = $request->file('front');
            $back = $request->file('back');
            $result = $this->screening->screen($front, $back, $data['document_type']);
            $paths = ['front' => null, 'back' => null];
            if ($this->documents->passes($result)) {
                $paths = ['front' => $user['id'].'/'.$session.'/front.enc', 'back' => $back ? $user['id'].'/'.$session.'/back.enc' : null];
                $this->backend->setDocumentPaths($user['id'], $session, $paths);
                foreach (['front' => $front, 'back' => $back] as $kind => $file) {
                    if (! $file) { continue; }
                    $path = $user['id'].'/'.$session.'/'.$kind.'.enc';
                    $this->backend->putDocument($path, $this->documents->encrypt($file->getContent(), $file->getMimeType(), $path));
                    $paths[$kind] = $path;
                }
            }
            $status = $this->backend->rpc('finish_document_submission', ['p_user' => $user['id'], 'p_session' => $session, 'p_result' => $result, 'p_front' => $paths['front'], 'p_back' => $paths['back']]);
            $reason = ($result['front']['reason'] ?? null) ?: ($result['back']['reason'] ?? null);
            $why = [
                'looks_like_a_screenshot' => 'This looks like a screenshot, not a photo of an ID card.',
                'too_much_text' => 'This image has too much text to be an ID card (it looks like a chat or a web page).',
                'no_portrait_found' => 'We could not find your photo on the document. The front side must show your portrait clearly.',
                'image_unreadable' => 'The image is too blurry or too dark to read.',
                'invalid_image' => 'This image could not be read.',
            ][$reason] ?? 'This is not recognized as the selected identity document.';
            return response()->json(['status' => $status, 'message' => $status === 'manual_review'
                ? 'Your document was received and is awaiting administrator review.'
                : $why.' Please upload the correct, clear photo of your actual ID document.']);
        } catch (\Throwable) {
            $this->backend->failDocument($user['id'], $session);
            return response()->json(['message' => 'Document screening is unavailable. Your account has not been approved. Please retry shortly.'], 503);
        }
    }
    public function destroy(Request $request): JsonResponse
    {
        $user = $this->backend->customer($request->bearerToken());
        abort_unless($user, 401);
        $this->backend->rpc('delete_document_submission', ['p_user' => $user['id']]);
        return response()->json(['status' => 'not_started']);
    }
}
