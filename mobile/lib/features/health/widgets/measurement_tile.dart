import 'package:flutter/material.dart';

import '../../../design_system/tokens.dart';
import '../health_models.dart';

/// One reading: type + Thai timestamp on the left, value + unit on the right.
class MeasurementTile extends StatelessWidget {
  const MeasurementTile({super.key, required this.measurement});

  final HealthMeasurement measurement;

  @override
  Widget build(BuildContext context) {
    final String dateLabel = measurement.recordedAtLabel;
    final String contextLabel = measurement.contextLabel;

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(AppSpacing.lg),
      decoration: BoxDecoration(
        color: AppColors.surfaceRaised,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(color: AppColors.border),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Text(measurement.typeLabel, style: AppType.sectionTitle),
                const SizedBox(height: AppSpacing.xs),
                Text(
                  dateLabel.isEmpty ? 'ไม่ทราบวันที่' : dateLabel,
                  style: AppType.caption,
                ),
                if (contextLabel.isNotEmpty) ...<Widget>[
                  const SizedBox(height: AppSpacing.xs),
                  Text(contextLabel, style: AppType.caption),
                ],
              ],
            ),
          ),
          const SizedBox(width: AppSpacing.md),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: <Widget>[
              Text(measurement.valueLabel, style: AppType.vitalValue),
              const SizedBox(height: AppSpacing.xs),
              Text(measurement.unitLabel, style: AppType.caption),
            ],
          ),
        ],
      ),
    );
  }
}
