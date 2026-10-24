<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Permission catalog
    |--------------------------------------------------------------------------
    |
    | The 99 permission IDs frozen by
    | docs/baseline-v1.0.0/PERMISSION_CATALOG.md (v1.2.0, including the four
    | CR-001 additions and the six CR-003 additions). This list is normative:
    | never invent an ID here.
    | The list is sorted so drift is easy to spot in review.
    |
    */

    'permissions' => [
        'access_grant.create',
        'access_grant.read',
        'access_grant.revoke',
        'access_grant.update',
        'ai.conversation.create',
        'ai.conversation.delete_request',
        'ai.conversation.read',
        'ai.health_context.use',
        'ai.report.create',
        'allergy.read',
        'allergy.update',
        'appointment.cancel',
        'appointment.create',
        'appointment.read',
        'appointment.update',
        'audit_event.read',
        'audit_event.read_own',
        'break_glass.approve',
        'break_glass.request',
        'break_glass.use',
        'care_plan.create',
        'care_plan.read',
        'care_plan.update',
        'care_relationship.create',
        'care_relationship.read',
        'care_relationship.revoke',
        'care_relationship.update',
        'care_task.accept',
        'care_task.assign',
        'care_task.block',
        'care_task.cancel',
        'care_task.complete',
        'care_task.create',
        'care_task.evidence.create',
        'care_task.evidence.read',
        'care_task.read',
        'care_task.reopen',
        'care_task.start',
        'care_task.template.create',
        'care_task.template.deactivate',
        'care_task.template.read',
        'care_task.template.update',
        'care_task.verify',
        'consent.read',
        'consent.record',
        'consent.withdraw',
        'data_export.cancel',
        'data_export.download',
        'data_export.request',
        'escalation_policy.read',
        'escalation_policy.update',
        'health_condition.correct',
        'health_condition.create',
        'health_condition.read',
        'health_measurement.correct',
        'health_measurement.create',
        'health_measurement.delete',
        'health_measurement.read',
        'health_profile.archive',
        'health_profile.create',
        'health_profile.read',
        'health_profile.update',
        'health_report.create',
        'health_report.export',
        'health_report.read',
        'integration.health_data.read',
        'integration.health_data.write',
        'legal_authority.verify',
        'medical_document.delete',
        'medical_document.export',
        'medical_document.read',
        'medical_document.share',
        'medical_document.upload',
        'medication_plan.create',
        'medication_plan.discontinue',
        'medication_plan.read',
        'medication_plan.update',
        'medication_record.correct',
        'medication_record.create',
        'medication_record.read',
        'notification.delivery.read',
        'notification.preference.read',
        'notification.preference.update',
        'person.create',
        'person.merge',
        'person.read',
        'person.update',
        'platform.configuration.manage',
        'platform.user.support',
        'security_access_review.read',
        'timeline.read',
        'workspace.invitation.accept',
        'workspace.member.invite',
        'workspace.member.read',
        'workspace.member.remove',
        'workspace.member.suspend',
        'workspace.member.update_role',
        'workspace.read',
        'workspace.update',
    ],

    /*
    |--------------------------------------------------------------------------
    | Resource scopes (.ai/03-AUTHORIZATION.md §5)
    |--------------------------------------------------------------------------
    |
    | Declaring a scope means the system understands the token, not that the
    | scope is enabled in the MVP. See `disabled_scopes` below (ADR-014).
    |
    */

    'scopes' => [
        'profile.basic',
        'vitals',
        'medications',
        'appointments',
        'documents',
        'tasks',
        'timeline',
        'ai_context',
        'mental_health',
        'lab_results',
    ],

    /*
    |--------------------------------------------------------------------------
    | Scopes disabled in the MVP (ADR-014)
    |--------------------------------------------------------------------------
    |
    | Granting either scope must be rejected with 422. They remain in the
    | vocabulary so the API can recognise and refuse them explicitly.
    |
    */

    'disabled_scopes' => [
        'mental_health',
        'lab_results',
    ],

    /*
    |--------------------------------------------------------------------------
    | Consent purposes (.ai/04-CONSENT-DELEGATION.md §2.1)
    |--------------------------------------------------------------------------
    |
    | Only purposes listed here require a consent_records row. Purposes such as
    | care_coordination rely on another lawful basis but are still audited.
    |
    */

    'consent_purposes' => [
        'ai_processing',
        'data_export',
        'third_party_share',
    ],

    /*
    |--------------------------------------------------------------------------
    | Actions an access grant may delegate
    |--------------------------------------------------------------------------
    */

    'actions' => [
        'read',
        'create',
        'update',
        'export',
        'share',
        'delegate',
    ],

];
