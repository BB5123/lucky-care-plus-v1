import 'package:flutter/material.dart';

import '../../../design_system/status_chip.dart';
import '../../../design_system/tokens.dart';
import '../family_models.dart';

/// One member row: name, role label and status chip.
class MemberTile extends StatelessWidget {
  const MemberTile({super.key, required this.member});

  final FamilyMembership member;

  @override
  Widget build(BuildContext context) {
    final String name = member.name.isEmpty ? 'สมาชิกในครอบครัว' : member.name;

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(AppSpacing.lg),
      decoration: BoxDecoration(
        color: AppColors.surfaceRaised,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(color: AppColors.border),
      ),
      child: Row(
        children: <Widget>[
          Container(
            width: AppSpacing.xxxl,
            height: AppSpacing.xxxl,
            decoration: const BoxDecoration(
              color: AppColors.infoSurface,
              shape: BoxShape.circle,
            ),
            child: const Icon(
              Icons.person_outline,
              color: AppColors.info,
              size: AppSpacing.xl,
            ),
          ),
          const SizedBox(width: AppSpacing.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Text(name, style: AppType.title),
                const SizedBox(height: AppSpacing.xs),
                Text(member.roleLabel, style: AppType.caption),
              ],
            ),
          ),
          const SizedBox(width: AppSpacing.sm),
          StatusChip(
            label: member.statusLabel,
            tone: _statusTone(member.status),
          ),
        ],
      ),
    );
  }

  static AppChipTone _statusTone(String status) {
    return switch (status) {
      'active' => AppChipTone.success,
      'invited' || 'pending' || 'suspended' => AppChipTone.warning,
      'removed' || 'revoked' => AppChipTone.neutral,
      _ => AppChipTone.neutral,
    };
  }
}
