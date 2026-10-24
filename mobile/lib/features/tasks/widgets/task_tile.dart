import 'package:flutter/material.dart';

import '../../../design_system/status_chip.dart';
import '../../../design_system/tokens.dart';
import '../task_models.dart';

/// One task row: title, tier chip and the Thai due time.
class TaskTile extends StatelessWidget {
  const TaskTile({super.key, required this.task, required this.onTap});

  final CareTask task;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final String dueLabel = task.dueLabel;
    final String? profileName = task.profileDisplayName;

    return Material(
      color: AppColors.surfaceRaised,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(AppRadius.lg),
        side: const BorderSide(color: AppColors.border),
      ),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.lg),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: <Widget>[
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: <Widget>[
                  Expanded(child: Text(task.title, style: AppType.title)),
                  const SizedBox(width: AppSpacing.sm),
                  StatusChip(label: task.tierLabel, tone: _tierTone(task.tier)),
                ],
              ),
              const SizedBox(height: AppSpacing.sm),
              Row(
                children: <Widget>[
                  const Icon(
                    Icons.schedule,
                    size: AppSpacing.lg,
                    color: AppColors.textSecondary,
                  ),
                  const SizedBox(width: AppSpacing.xs),
                  Expanded(
                    child: Text(
                      dueLabel.isEmpty ? 'ไม่ระบุเวลา' : dueLabel,
                      style: AppType.caption,
                    ),
                  ),
                  if (profileName != null && profileName.isNotEmpty)
                    Text(profileName, style: AppType.caption),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }

  static AppChipTone _tierTone(String tier) {
    return switch (tier) {
      'delegated' => AppChipTone.info,
      'clinical' => AppChipTone.warning,
      _ => AppChipTone.neutral,
    };
  }
}
