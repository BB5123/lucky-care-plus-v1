# Documents module

Private medical-document pipeline (ADR-014, `.ai/06` §4, `.ai/09` §2.2).

## Surface

| Method | Path | Permission |
|---|---|---|
| `GET` | `/api/v1/workspaces/{workspace}/profiles/{profile}/documents` | `medical_document.read` |
| `POST` | `.../documents` (multipart, field `file`) | `medical_document.upload` |
| `GET` | `.../documents/{document}` | `medical_document.read` |
| `POST` | `.../documents/{document}/url` | `medical_document.export` |
| `DELETE` | `.../documents/{document}` | `medical_document.delete` |
| `GET` | `/api/v1/documents/{document}/download?signature=...` | `medical_document.export` (signed delivery) |

The five workspace-scoped routes run `workspace.context` and the normal
`authorize.permission` middleware. The signed route has no workspace/profile
segment, so `documents.download.context` resolves the document through the
SELECT-only `document_download_lookup` RLS policy, injects the trusted
workspace/profile route parameters, and lets the same `EnsureAuthorized`
pipeline re-check current access. A revoked grant therefore invalidates an
already-issued link (ABD-014 / F-08).

The baseline catalog says a signed URL is an **export/download**, separate from
read (`docs/baseline-v1.0.0/PERMISSION_CATALOG.md`), so URL issuance and
delivery use `medical_document.export`. The task brief's route table listed
`medical_document.read` for URL issuance; the baseline wins and the difference
is reported.

## Object layout

```
documents/{tenant_uuid}/{document_uuid}
```

- No profile id, user filename, extension, or health-data fragment in the key.
- `storage_disk` and `storage_path` are stored on the metadata row;
  `storage_path` and `original_name` use the Laravel `encrypted` cast, so a
  database dump alone does not reveal the private layout or a person's name.
- The local disk root is `storage/app/private`; the bucket/disk is never public.
- Downloads are sent as `Content-Disposition: attachment`, with
  `X-Content-Type-Options: nosniff` and `Cache-Control: no-store`.
- `original_name` is returned only on read-authorized surfaces (list/show), not
  in the upload acknowledgement.

## Validation and scanning

- The MIME type is sniffed from the bytes with `finfo`. The client-declared
  type is never trusted; a known client type that disagrees with the bytes is
  rejected with 422.
- Allowed types: PDF, JPEG, PNG, HEIC (`config/documents.php`).
- Maximum size: 10 MiB by default. An over-limit upload returns 422 with a Thai
  message that names the configured byte ceiling.
- A malware scanner is configured through `documents.scanner` (`null` or `clamav`); the default is
  fail-closed `UnavailableScanner`. With `documents.scan_required = false` (today's default) an accepted
  upload is stored with `scan_status = skipped` and a warning is logged. With it `true`, the scan runs
  before any object or row is written: `clean` stores `scan_status = clean`, `infected:*` returns
  `422 document_infected` and writes one sanitised security-audit row, and `error:*` keeps the existing
  `503 scan_unavailable` contract. See [`backend/docs/MALWARE-SCANNING.md`](../../../docs/MALWARE-SCANNING.md).

## Deletion and retention

`DELETE` soft-deletes metadata (`status = deleted`, `deleted_at`) and dispatches
`DeleteDocumentObject` to remove the private object off the request path; the
object is never deleted inline. The job carries only ids and opens its own
tenant/workspace context. `App\Modules\Retention\Services\PurgeService` owns the
hard delete: after the retention period it removes any remaining object (the
queue job normally did this already) and then hard-deletes the row, respecting
legal holds. Soft-deleted documents are included in the retention sweep;
`task_evidence.document_id` is cleared by the composite
`ON DELETE SET NULL (document_id)` foreign key.

## Evidence integration

`task_evidence.document_id` has a composite FK to
`medical_documents (id, tenant_id, workspace_id)`. `CareTaskService` only
accepts a document that is `available`, in the same profile/workspace, and
either clean or scan-skipped. Quarantined, pending and deleted documents fail
closed. Photo evidence requires a non-null document, and the clinical
completion check re-verifies that the referenced document is still available.