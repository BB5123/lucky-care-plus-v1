import 'package:flutter/material.dart';

import '../../../core/medication/medication_models.dart';
import '../../../design_system/tokens.dart';

/// The server's adherence numbers, shown exactly as received.
///
/// The strip is a picture of `daily[].percent`; it is not a second source of
/// truth and nothing here divides, rounds or recomputes the aggregate.
class AdherenceCard extends StatelessWidget {
  const AdherenceCard({super.key, required this.adherence});

  final MedicationAdherence adherence;

  @override
  Widget build(BuildContext context) {
    final List<AdherenceDay> days = adherence.daily.take(7).toList(
      growable: false,
    );

    return Container(
      key: Key('adherence-${adherence.planId}'),
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
          Text(
            'ความสม่ำเสมอ 7 วัน · ${adherence.name}',
            style: AppType.sectionTitle,
          ),
          const SizedBox(height: AppSpacing.md),
          Text(
            adherence.percentLabel,
            key: Key('adherence-percent-${adherence.planId}'),
            style: AppType.vitalValue,
          ),
          const SizedBox(height: AppSpacing.md),
          Wrap(
            spacing: AppSpacing.lg,
            runSpacing: AppSpacing.sm,
            children: <Widget>[
              _AdherenceMetric(
                key: Key('adherence-expected-${adherence.planId}'),
                label: 'คาดไว้',
                value: '${adherence.expected}',
              ),
              _AdherenceMetric(
                key: Key('adherence-taken-${adherence.planId}'),
                label: 'ทานแล้ว',
                value: '${adherence.taken}',
              ),
              _AdherenceMetric(
                key: Key('adherence-skipped-${adherence.planId}'),
                label: 'ข้าม',
                value: '${adherence.skipped}',
              ),
              _AdherenceMetric(
                key: Key('adherence-missed-${adherence.planId}'),
                label: 'พลาด',
                value: '${adherence.missed}',
              ),
            ],
          ),
          if (days.isNotEmpty) ...<Widget>[
            const SizedBox(height: AppSpacing.lg),
            SizedBox(
              height: AppSpacing.sm + AppSpacing.xxl,
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: <Widget>[
                  for (final AdherenceDay day in days)
                    Expanded(
                      child: Padding(
                        padding: const EdgeInsets.symmetric(
                          horizontal: AppSpacing.xs / 2,
                        ),
                        child: Align(
                          alignment: Alignment.bottomCenter,
                          child: Container(
                            key: Key(
                              'adherence-bar-${adherence.planId}-${day.date}',
                            ),
                            height:
                                AppSpacing.sm +
                                (AppSpacing.xxl * day.fraction),
                            decoration: BoxDecoration(
                              color: _barColor(day),
                              borderRadius: BorderRadius.circular(AppRadius.sm),
                            ),
                          ),
                        ),
                      ),
                    ),
                ],
              ),
            ),
          ],
          const SizedBox(height: AppSpacing.md),
          const Text(
            'ตัวเลขนี้คือรายงานที่บันทึกไว้ ไม่ใช่การยืนยันทางคลินิก',
            key: Key('adherence-disclaimer'),
            style: AppType.caption,
          ),
        ],
      ),
    );
  }

  Color _barColor(AdherenceDay day) {
    if (day.expected == 0) {
      return AppColors.border;
    }

    if (day.percent >= 100) {
      return AppColors.success;
    }

    if (day.percent > 0) {
      return AppColors.warning;
    }

    return AppColors.dangerSurface;
  }
}

/// One server number with its Thai label. The value is never formatted or
/// recomputed, so `66.7` stays `66.7`.
class _AdherenceMetric extends StatelessWidget {
  const _AdherenceMetric({
    super.key,
    required this.label,
    required this.value,
  });

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        Text(value, style: AppType.title),
        const SizedBox(height: AppSpacing.xs),
        Text(label, style: AppType.caption),
      ],
    );
  }
}
