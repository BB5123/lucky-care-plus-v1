import 'package:flutter/material.dart';

import '../../../design_system/status_chip.dart';
import '../../../design_system/tokens.dart';
import '../family_models.dart';

/// One pending invitation: address, role, expiry, and the two actions that
/// exist while the invitation is open.
///
/// The copy action is only rendered when this session still holds the token
/// (the create response). A row read back from the server cannot recreate the
/// secret, so it shows the email and expiry only.
class InvitationTile extends StatelessWidget {
  const InvitationTile({
    super.key,
    required this.invitation,
    required this.onCopy,
    required this.onRevoke,
  });

  final FamilyInvitation invitation;
  final VoidCallback onCopy;
  final VoidCallback onRevoke;

  @override
  Widget build(BuildContext context) {
    final String email = invitation.email.isEmpty
        ? 'คำเชิญที่ไม่มีอีเมล'
        : invitation.email;

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(AppSpacing.lg),
      decoration: BoxDecoration(
        color: AppColors.surfaceRaised,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(color: AppColors.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Row(
            children: <Widget>[
              const Icon(
                Icons.mark_email_unread_outlined,
                color: AppColors.info,
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(child: Text(email, style: AppType.title)),
              const SizedBox(width: AppSpacing.sm),
              const StatusChip(
                label: 'รอตอบรับ',
                tone: AppChipTone.warning,
              ),
            ],
          ),
          const SizedBox(height: AppSpacing.xs),
          Text(invitation.roleLabel, style: AppType.caption),
          const SizedBox(height: AppSpacing.xs),
          Text(
            invitation.expiryLabel,
            key: Key('family-invitation-expiry-${invitation.id}'),
            style: AppType.caption,
          ),
          const SizedBox(height: AppSpacing.sm),
          Wrap(
            spacing: AppSpacing.sm,
            runSpacing: AppSpacing.xs,
            children: <Widget>[
              if (invitation.hasLink)
                TextButton.icon(
                  key: Key('family-invitation-copy-${invitation.id}'),
                  onPressed: onCopy,
                  icon: const Icon(Icons.copy_outlined, size: AppSpacing.xl),
                  label: const Text('คัดลอกลิงก์เชิญ'),
                ),
              TextButton(
                key: Key('family-invitation-revoke-${invitation.id}'),
                onPressed: onRevoke,
                style: TextButton.styleFrom(foregroundColor: AppColors.danger),
                child: const Text('ยกเลิกคำเชิญ'),
              ),
            ],
          ),
        ],
      ),
    );
  }
}
