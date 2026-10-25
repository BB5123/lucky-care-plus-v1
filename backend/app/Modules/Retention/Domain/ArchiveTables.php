<?php

namespace App\Modules\Retention\Domain;

/**
 * The tables an archive contains, in FK-safe restore order (P4b).
 *
 * Both the inventory (`ArchiveManifestService`) and the encrypted bundle
 * (`ArchiveBundleService`) read this list, so "what the manifest counts" and
 * "what the bundle holds" cannot drift apart. Tables that do not exist in a
 * deployment are skipped, never reported as zero.
 *
 * `users` and `workspace_memberships` are deliberately absent: a user is a
 * global account that may belong to other workspaces, and re-creating
 * memberships is a restore decision (re-invite), not archived content.
 */
final class ArchiveTables
{
    /**
     * Parents before children, so a future restore can insert in this order.
     *
     * @var list<string>
     */
    public const ALL = [
        'persons',
        'health_profiles',
        'health_conditions',
        'allergies',
        'health_measurements',
        'medication_plans',
        'medication_records',
        'appointments',
        'care_tasks',
        'task_events',
        'task_evidence',
        'medical_documents',
        'timeline_entries',
        'notification_deliveries',
        'ai_conversations',
    ];
}
