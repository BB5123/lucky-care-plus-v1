import 'package:flutter/material.dart';

import '../../../core/medication/medication_models.dart';
import '../../../design_system/status_chip.dart';
import '../../../design_system/tokens.dart';

/// One medication plan. A discontinued plan is dimmed, states its reason and
/// deliberately offers no record action.
class MedicationPlanCard extends StatelessWidget {
  const MedicationPlanCard({
    super.key,
    required this.plan,
    this.onRecord,
  });

  final MedicationPlan plan;
  final VoidCallback? onRecord;

  @override
  Widget build(BuildContext context) {
    final bool active = plan.isActive;
    final String? reason = plan.discontinuedReason;

    final Widget card = Container(
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
            crossAxisAlignment: CrossAxisAlignment.start,
            children: <Widget>[
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: <Widget>[
                    Text(
                      plan.name,
                      key: Key('medication-plan-name-${plan.id}'),
                      style: AppType.title,
                    ),
                    const SizedBox(height: AppSpacing.xs),
                    Text(
                      plan.dosage,
                      key: Key('medication-plan-dosage-${plan.id}'),
                      style: AppType.bodySecondary,
                    ),
                  ],
                ),
              ),
              const SizedBox(width: AppSpacing.sm),
              StatusChip(
                key: Key('medication-plan-status-${plan.id}'),
                label: plan.statusLabel,
                tone: active ? AppChipTone.success : AppChipTone.neutral,
              ),
            ],
          ),
          const SizedBox(height: AppSpacing.md),
          Text(
            plan.doseLabel,
            key: Key('medication-plan-dose-${plan.id}'),
            style: AppType.label,
          ),
          if (plan.scheduleLabel.isNotEmpty) ...<Widget>[
            const SizedBox(height: AppSpacing.xs),
            Text(
              plan.scheduleLabel,
              key: Key('medication-plan-schedule-${plan.id}'),
              style: AppType.bodySecondary,
            ),
          ],
          const SizedBox(height: AppSpacing.xs),
          Text(
            plan.periodLabel,
            key: Key('medication-plan-window-${plan.id}'),
            style: AppType.caption,
          ),
          if (plan.instructions != null) ...<Widget>[
            const SizedBox(height: AppSpacing.sm),
            Text(plan.instructions!, style: AppType.caption),
          ],
          if (plan.isDiscontinued && reason != null) ...<Widget>[
            const SizedBox(height: AppSpacing.md),
            Text(
              'เหตุผลที่หยุด: $reason',
              key: Key('medication-plan-reason-${plan.id}'),
              style: AppType.label.copyWith(color: AppColors.textSecondary),
            ),
          ],
          if (active && onRecord != null) ...<Widget>[
            const SizedBox(height: AppSpacing.lg),
            FilledButton(
              key: Key('medication-record-${plan.id}'),
              onPressed: onRecord,
              style: FilledButton.styleFrom(
                backgroundColor: AppColors.info,
                foregroundColor: AppColors.textOnAccent,
                minimumSize: const Size.fromHeight(AppSpacing.xxxl),
                textStyle: AppType.button,
              ),
              child: const Text('บันทึกการทานยา'),
            ),
          ],
        ],
      ),
    );

    return Opacity(
      key: Key('medication-plan-${plan.id}'),
      opacity: active ? 1 : 0.55,
      child: card,
    );
  }
}
