import 'package:flutter/material.dart';

import '../../../design_system/status_dot.dart';
import '../../../design_system/tokens.dart';
import '../../../design_system/zone_card.dart';
import '../dashboard_models.dart';

/// Zone 1 — the family switcher. Names plus a status dot; tapping a member is
/// not part of this slice, so the chips are deliberately non-interactive.
class FamilyZoneWidget extends StatelessWidget {
  const FamilyZoneWidget({super.key, required this.members});

  static const Key zoneKey = Key('zone-family');

  final List<FamilyMember> members;

  @override
  Widget build(BuildContext context) {
    return ZoneCard(
      title: 'ครอบครัว',
      icon: Icons.family_restroom,
      child: members.isEmpty
          ? const Text('ยังไม่มีสมาชิกในครอบครัว', style: AppType.bodySecondary)
          : Wrap(
              spacing: AppSpacing.sm,
              runSpacing: AppSpacing.sm,
              children: members
                  .map((FamilyMember member) => _MemberChip(member: member))
                  .toList(growable: false),
            ),
    );
  }
}

class _MemberChip extends StatelessWidget {
  const _MemberChip({required this.member});

  final FamilyMember member;

  @override
  Widget build(BuildContext context) {
    final String name = member.displayName.isEmpty
        ? 'สมาชิกในครอบครัว'
        : member.displayName;

    return Container(
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.md,
        vertical: AppSpacing.sm,
      ),
      decoration: BoxDecoration(
        color: AppColors.surfaceRaised,
        borderRadius: BorderRadius.circular(AppRadius.pill),
        border: Border.all(color: AppColors.border),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          StatusDot(status: member.status),
          const SizedBox(width: AppSpacing.sm),
          Text(name, style: AppType.label),
        ],
      ),
    );
  }
}
