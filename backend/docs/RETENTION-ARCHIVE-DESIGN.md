# P4b-2 design: restore an archived account, then delete the archive

> **Status: design only (2026-10-26).** P4b-1 shipped the encrypted bundle (`ArchiveBundleService`,
> `retention:archive-drill`, `RET-B01..B04`). Nothing in this document is implemented yet, and the final
> `scheduled_deletion -> deleted` arrow stays closed until it is. This file exists so P4b-2 starts from the
> decisions that are actually hard, instead of from a data mover.

## 1. Where P4b-1 stops

The bundle is a **copy, not a move**: the active rows are still in the database, hidden by the state gate and
excluded from jobs. That is deliberate, because it makes P4b-1 safe, but it leaves three holes:

1. **No restore proof.** A bundle can be verified (hash, decryptability, counts) but never used to reconstruct a
   workspace, so "we can restore the archive" is still a claim.
2. **No per-archive key.** Every bundle is encrypted with `APP_KEY`. Losing the app key loses every archive at once,
   and "delete this account's archive" cannot be proven by destroying one key.
3. **Export still reads the tables.** In `archived`/`scheduled_deletion` the gate allows export; if the rows moved out,
   the current export endpoint would return an empty account. Restore alone is not enough - export from the bundle is
   part of moving out.

## 2. Two owner decisions (do not implement past them without an answer)

**Q-A. Move or copy on archive?** Today: copy. The recommended design is **move** (delete the active rows after a
verified bundle), because otherwise the archive is a second copy and the deletion promise is still carried by the
active tables. Moving has a cost: support restores become the only way back, and export must read the bundle.

**Q-B. Is an account allowed to be missing from the active tables for the whole archived window (up to 11 months)?**
If the answer is no, P4b-2 stops at "bundle as an extra copy" and the remaining work is export-from-bundle and
crypto-shred only. If yes, the move sequence in §3.2 and the restore path in §3.3 are both required.

## 3. Design

### 3.1 Per-archive key (P4b-2a)

- A random 32-byte key per archive, generated at bundle time.
- Bundle encrypted with `sodium_crypto_secretstream_xchacha20poly1305` (streaming, authenticated), replacing the
  current `Crypt::encryptString(gzencode(...))`.
- The archive key is wrapped with the app key (`Crypt::encryptString($archiveKey)`) and stored on the manifest
  (`key_wrapped`, plus `key_algorithm`). A separate table is not needed for one key per manifest.
- **Crypto-shred** = delete `key_wrapped` (and overwrite the column). The ciphertext may remain, in the archive and in
  backups, but it is unreadable; the audit row records the shred.

### 3.2 Move-out sequence (P4b-2b, only if Q-A = move)

Inside one transaction, after `retention:archive-drill` passes:

1. `DB::table(<child>) ... ->delete()` for each table in **child -> parent** order: `task_evidence`, `task_events`,
   `timeline_entries`, `medication_records`, `care_tasks`, `appointments`, `medical_documents`,
   `health_measurements`, `medication_plans`, `allergies`, `health_conditions`, `notification_deliveries`,
   `ai_conversations`, `health_profiles`, `persons`.
2. Set `data_moved_at` on the manifest; write one `retention.archive_move` audit row with the per-table counts
   (counts only - N-02).
3. The bundle and the manifest stay; **document objects stay on the documents disk** for now (they are encrypted at
   rest, outside the app, and P4c owns moving them). A move that deletes `medical_documents` rows leaves those objects
   orphaned by design; the manifest records the document count so a later collector can find them.
4. Do **not** run the move for a workspace with an active legal hold (`LegalHold` stays the veto).

### 3.3 Restore (P4b-2a)

`retention:archive-restore {workspace} --confirm [--dry-run]`

1. Require state `archived` or `scheduled_deletion`, a verified bundle, and `data_moved_at` set (otherwise the rows are
   still there - a restore would duplicate them; in that case the command only restores the state).
2. Refuse if any of the archive tables already has a row for the workspace unless `--merge` (then existing ids are
   kept; missing rows are inserted).
3. Insert in **parent -> child** order from §3.2 reversed; ids are the original UUIDv7s, so referential links survive.
   Soft-deleted rows keep `deleted_at`.
4. Restore the state to `active` through `WorkspaceLifecycle::restore` (audited `retention.state`), then write one
   `retention.archive_restore` audit row with counts. One transaction: an interrupted restore leaves nothing half-done.
5. Objects: require the referenced objects to exist on the configured disk; a missing object fails the restore with a
   count, it is not silently skipped (the account would look complete and lose its files).

### 3.4 Export from the bundle (P4b-2a, needed only if Q-A = move)

The data export endpoint in `archived` must read the bundle when `data_moved_at` is set: stream the NDJSON, map tables
to export sections, and keep the existing `data_export.*` authorization and `RET-G02` behaviour. This is the reason the
move waits for the export path - otherwise a member's export offer at day 1 would return an empty account.

### 3.5 Deletion of the archive (P4c)

When `scheduled_deletion` expires and the final arrow is allowed:

1. Require `data_moved_at` and a verified bundle (or a deliberately empty account).
2. Delete document objects (P4c collector), then delete `key_wrapped` (crypto-shred), set `shredded_at`, write
   `retention.archive_delete` with counts, then `WorkspaceLifecycle::markDeleted`.
3. A guard in `markDeleted` refuses unless `shredded_at` is set (fail closed): the state cannot say "deleted" while an
   unreadable-but-present bundle has no shred record.

### 3.6 Acceptance tests (add to `tests/Feature/Retention/`)

| ID | Proves |
|---|---|
| `ARC-R01` | Restore from a moved workspace reconstructs exact per-table counts and a known measurement value; state becomes `active`; audit rows exist. |
| `ARC-R02` | A tampered bundle is refused **before** any insert (hash or decrypt failure), and the state is unchanged. |
| `ARC-R03` | Move-out deletes every table's rows, keeps the manifest + bundle, and `retention:archive-drill` still passes. |
| `ARC-R04` | After crypto-shred, the drill reports unreadable, and the bytes are still there (shred != delete). |
| `ARC-D01` | `markDeleted` refuses without `shredded_at`, so the final arrow cannot precede the shred. |
| `ARC-E01` | Export in `archived` after a move returns the bundle content, not an empty account. |

### 3.7 Migration and rollout

- One migration: the key + timestamps columns on `workspace_archive_manifests` (`key_wrapped` text nullable,
  `key_algorithm` string nullable, `data_moved_at` / `shredded_at` timestamptz nullable).
- Both move and restore are console commands, not HTTP: no new permission ID is needed (this is the same reason P3c
  is blocked on a permission change record - do not invent one here).
- The move is off by default (`RETENTION_ARCHIVE_MOVE=false`); a deployment enables it only after the restore drill
  passes on its own data.

## 4. Open questions for the owner

1. Q-A / Q-B above - the branch point for everything else.
2. Restore SLA (hours or days?) and who authorizes a restore (support lead, DPO?).
3. Key custody: wrap with `APP_KEY` (simple, one key to escrow) or an external KMS (better separation, more moving
   parts). The answer decides §3.1.
4. Must the archive disk be encrypted at rest **as well** (double encryption), and does it need the same off-site
   copy as the database (RPO/RTO, D3)?
5. How long are manifests kept after deletion? The audit chain needs the counts; the bundle must not be kept "for
   statistics".

Until these are answered, the honest state is: **the copy exists and is verifiable; the archive is neither restorable
nor deletable, and the final arrow stays closed.**
