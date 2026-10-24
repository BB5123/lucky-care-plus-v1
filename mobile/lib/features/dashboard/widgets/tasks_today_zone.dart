import 'package:flutter/material.dart';

import '../../../design_system/tokens.dart';
import '../../../design_system/zone_card.dart';
import '../dashboard_models.dart';

/// Zone 3 — today's care tasks as `done / total` plus the overdue count.
class TasksTodayZoneWidget extends StatelessWidget {
  const TasksTodayZoneWidget({super.key, required this.zone});

  static const Key zoneKey = Key('zone-tasks');

  final TasksTodayZone zone;

  @override
  Widget build(BuildContext context) {
    return ZoneCard(
      title: 'ภารกิจวันนี้',
      icon: Icons.checklist_rtl,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Text('สำเร็จ ${zone.done}/${zone.total}', style: AppType.title),
          const SizedBox(height: AppSpacing.md),
          ClipRRect(
            borderRadius: BorderRadius.circular(AppRadius.pill),
            child: LinearProgressIndicator(
              value: zone.progress,
              minHeight: AppSpacing.sm,
              backgroundColor: AppColors.border,
              color: AppColors.success,
            ),
          ),
          if (zone.overdue > 0) ...<Widget>[
            const SizedBox(height: AppSpacing.md),
            Row(
              children: <Widget>[
                const Icon(
                  Icons.schedule,
                  size: AppSpacing.lg,
                  color: AppColors.warning,
                ),
                const SizedBox(width: AppSpacing.sm),
                Text(
                  'เลยกำหนด ${zone.overdue} รายการ',
                  style: AppType.label.copyWith(color: AppColors.warning),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}
