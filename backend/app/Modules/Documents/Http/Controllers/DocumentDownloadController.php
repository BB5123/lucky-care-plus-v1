<?php

namespace App\Modules\Documents\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Documents\Domain\DocumentNotFoundException;
use App\Modules\Documents\Models\MedicalDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Delivers the private object after the signed route and the Authorizer have
 * re-proved current access. The object is always served as a download with
 * `nosniff` and no caching, and the filename is derived from the document id
 * and MIME type — never from the stored original filename (N-02, `.ai/09` §2.2).
 */
final class DocumentDownloadController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        $document = $request->attributes->get('document');

        if (! $document instanceof MedicalDocument) {
            throw new DocumentNotFoundException;
        }

        $storagePath = $document->storage_path;

        if (! is_string($storagePath) || $storagePath === '') {
            throw new DocumentNotFoundException;
        }

        $disk = Storage::disk($document->storage_disk);

        if (! $disk->exists($storagePath)) {
            throw new DocumentNotFoundException;
        }

        $mime = $document->mime_type;
        $filename = 'document-'.$document->id.'.'.$this->extensionFor($mime);

        $headers = [
            'Content-Type' => $mime,
            'Content-Length' => (string) $document->size_bytes,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ];

        return response()->streamDownload(
            function () use ($disk, $storagePath): void {
                $stream = $disk->readStream($storagePath);

                if (! is_resource($stream)) {
                    return;
                }

                fpassthru($stream);
                fclose($stream);
            },
            $filename,
            $headers,
        );
    }

    private function extensionFor(string $mime): string
    {
        $extensions = (array) config('documents.extensions', []);

        return is_string($extensions[$mime] ?? null) ? $extensions[$mime] : 'bin';
    }
}
