<?php

namespace App\Modules\Documents\Http\Middleware;

use App\Http\Responses\ProblemDetails;
use App\Modules\Documents\Models\MedicalDocument;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Database\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Resolves the document for the signed download route.
 *
 * The public-looking route has no `{workspace}` or `{profile}` segment, so
 * `workspace.context` cannot open the request transaction. This middleware:
 *
 *   1. looks the document up by id through the SELECT-only
 *      `document_download_lookup` RLS policy (transaction-local GUC);
 *   2. rejects soft-deleted/quarantined documents;
 *   3. opens the tenant/workspace context and injects the workspace and profile
 *      route parameters, so the ordinary `EnsureAuthorized` middleware can run
 *      the same Authorizer pipeline as every other endpoint.
 *
 * The id is never treated as authorization: the Authorizer still proves active
 * membership, role, ownership/grant, consent, and (for export) read scope.
 */
final class ResolveDocumentDownloadContext
{
    private const LOOKUP_SETTING = 'app.document_lookup_id';

    public function handle(Request $request, Closure $next): Response
    {
        $documentId = $request->route('document');

        if (! is_string($documentId) || ! Str::isUuid($documentId)) {
            return $this->notFound($request);
        }

        return DB::transaction(function () use ($request, $next, $documentId): Response {
            DB::selectOne(
                'select set_config(?, ?, true) as value',
                [self::LOOKUP_SETTING, $documentId],
            );

            try {
                $document = MedicalDocument::withTrashed()
                    ->whereKey($documentId)
                    ->first();

                if (
                    ! $document instanceof MedicalDocument
                    || $document->deleted_at !== null
                    || $document->status !== MedicalDocument::STATUS_AVAILABLE
                ) {
                    return $this->notFound($request);
                }

                TenantContext::set($document->tenant_id, $document->workspace_id);

                $workspace = Workspace::query()
                    ->whereKey($document->workspace_id)
                    ->first();

                if (! $workspace instanceof Workspace) {
                    return $this->notFound($request);
                }

                // Let the normal declaration layer build its ResourceRef from
                // the same trusted route shape used by every other endpoint.
                $request->route()->setParameter('workspace', $workspace->id);
                $request->route()->setParameter('profile', $document->profile_id);

                $request->attributes->set('workspace', $workspace);
                $request->attributes->set('document', $document);

                return $next($request);
            } finally {
                $this->clearLookup();

                try {
                    TenantContext::clear();
                } catch (Throwable) {
                    // Rollback already discarded the transaction-local context.
                }
            }
        });
    }

    private function notFound(Request $request): Response
    {
        return ProblemDetails::make(
            $request,
            404,
            'out_of_scope',
            'Resource not accessible',
            'ไม่พบข้อมูลที่ร้องขอ',
        );
    }

    private function clearLookup(): void
    {
        try {
            DB::statement("select set_config('".self::LOOKUP_SETTING."', '', false)");
        } catch (Throwable) {
            // A closed/aborted connection already discarded the
            // transaction-local value; there is nothing left to clean up.
        }
    }
}
