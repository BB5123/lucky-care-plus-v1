<?php

namespace App\Modules\Documents\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Documents\Application\DocumentService;
use App\Modules\Documents\Domain\DocumentNotFoundException;
use App\Modules\Documents\Http\Requests\StoreDocumentRequest;
use App\Modules\Documents\Http\Resources\MedicalDocumentResource;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Document collection, metadata, signed-link issuance and deletion.
 *
 * Every route declares its frozen `medical_document.*` permission and is
 * authorized before this controller runs. Workspace/profile identity comes
 * only from the resolved route context (N-01); the document id is always
 * re-scoped to that profile by the service.
 */
final class DocumentController extends Controller
{
    public function __construct(private readonly DocumentService $documents) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'cursor' => ['nullable', 'string', 'max:1000'],
        ]);

        $page = $this->documents->paginate(
            $this->workspace($request),
            $this->profile($request),
            (int) ($data['limit'] ?? 20),
            $data['cursor'] ?? null,
        );

        // This endpoint is read-authorized, so the encrypted filename may be
        // decrypted for the caller.
        $request->attributes->set('documents.expose_original_name', true);

        return response()->json([
            'data' => MedicalDocumentResource::collection($page->items())->resolve($request),
            'meta' => [
                'next_cursor' => $page->nextCursor()?->encode(),
                'limit' => $page->perPage(),
            ],
        ]);
    }

    public function store(StoreDocumentRequest $request): JsonResponse
    {
        $document = $this->documents->upload(
            $this->workspace($request),
            $this->profile($request),
            $this->actor($request),
            $request->file('file'),
            $request->validated(),
        );

        // Upload permission does not imply read permission, so the filename is
        // deliberately omitted from the acknowledgement.
        return response()->json(
            ['data' => (new MedicalDocumentResource($document))->resolve($request)],
            201,
        );
    }

    public function show(Request $request): JsonResponse
    {
        $document = $this->documents->findAvailable(
            $this->workspace($request),
            $this->profile($request),
            (string) $request->route('document'),
        );

        $request->attributes->set('documents.expose_original_name', true);

        return response()->json([
            'data' => (new MedicalDocumentResource($document))->resolve($request),
        ]);
    }

    /**
     * Issue a short-lived signed link. The actual download is a separate
     * signed route that re-checks current authorization at delivery time
     * (ABD-014 / F-08).
     */
    public function url(Request $request): JsonResponse
    {
        $document = $this->documents->findAvailable(
            $this->workspace($request),
            $this->profile($request),
            (string) $request->route('document'),
        );

        $signed = $this->documents->temporaryDownloadUrl($document);

        return response()->json([
            'data' => [
                'document_id' => $document->id,
                'url' => $signed['url'],
                'expires_at' => $signed['expires_at']->toIso8601String(),
            ],
        ]);
    }

    public function destroy(Request $request): Response
    {
        $this->documents->delete(
            $this->workspace($request),
            $this->profile($request),
            (string) $request->route('document'),
            $this->actor($request),
        );

        return response()->noContent();
    }

    private function workspace(Request $request): Workspace
    {
        $workspace = $request->attributes->get('workspace');

        if (! $workspace instanceof Workspace) {
            throw new DocumentNotFoundException;
        }

        return $workspace;
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();

        if (! $actor instanceof User) {
            throw new DocumentNotFoundException;
        }

        return $actor;
    }

    private function profile(Request $request): HealthProfile
    {
        $workspace = $this->workspace($request);
        $profileId = $request->route('profile');

        $profile = is_string($profileId)
            ? HealthProfile::query()
                ->where('tenant_id', $workspace->tenant_id)
                ->where('workspace_id', $workspace->id)
                ->whereKey($profileId)
                ->first()
            : null;

        if (! $profile instanceof HealthProfile) {
            throw new DocumentNotFoundException;
        }

        return $profile;
    }
}
