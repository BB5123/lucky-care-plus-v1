<?php

namespace App\Modules\Retention\Domain;

/**
 * The five account states of the retention model (RETENTION-MODEL.md section 2).
 *
 *    active -> read_only -> archived -> scheduled_deletion -> deleted
 *      ^          |            |
 *      +--- restore (payment, support request, or heir request) ---+
 *
 * A legal hold pins the account in `read_only` and blocks every outward arrow
 * (RET-T02, DM-R04). This enum only names the states and the capabilities each
 * one grants; the transitions live in `WorkspaceLifecycle` so that every state
 * change goes through one audited path.
 */
enum RetentionState: string
{
    case Active = 'active';
    case ReadOnly = 'read_only';
    case Archived = 'archived';
    case ScheduledDeletion = 'scheduled_deletion';
    case Deleted = 'deleted';

    /**
     * Reads are allowed in `active` and `read_only`; a deleted account has no
     * content left to read (only the audit trail survives).
     */
    public function canRead(): bool
    {
        return $this === self::Active || $this === self::ReadOnly;
    }

    /**
     * Only an active account accepts writes. `read_only` is exactly that.
     */
    public function canWrite(): bool
    {
        return $this === self::Active;
    }

    /**
     * Export is free in every plan and in every state except the final one
     * (RETENTION-MODEL.md section 3: "Export is free ... at every state").
     */
    public function canExport(): bool
    {
        return $this !== self::Deleted;
    }

    /**
     * The arrow that becomes due when `retention_expires_at` passes.
     *
     * `scheduled_deletion` has no automatic successor in P2: the final delete
     * is the member's "delete now" choice (P3) or the archive deletion (P4),
     * and marking an account `deleted` without removing its data would be a
     * lie. The command reports it as waiting instead.
     */
    public function nextOnExpiry(): ?self
    {
        return match ($this) {
            self::ReadOnly => self::Archived,
            self::Archived => self::ScheduledDeletion,
            default => null,
        };
    }

    /**
     * True when the account may receive scheduled background work: reminders,
     * materialised tasks, escalation and inactivity notices.
     *
     * A lapsed account keeps its data and stays readable (in `read_only`), but
     * processing it would be the "still nagging a family that stopped paying"
     * behaviour the model forbids. The predicate is defined once here so every
     * job asks the same question (P2b).
     */
    public function acceptsBackgroundWork(): bool
    {
        return $this === self::Active;
    }

    /**
     * True when the account can still be restored to `active`.
     */
    public function isRestorable(): bool
    {
        return $this !== self::Deleted;
    }
}
