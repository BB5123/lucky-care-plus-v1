import 'package:flutter/material.dart';

import '../../../core/format/thai_date.dart';
import '../../../design_system/status_chip.dart';
import '../../../design_system/tokens.dart';
import '../appointment_models.dart';

/// One read-only appointment row: title, Thai date/time and status chip.
class AppointmentTile extends StatelessWidget {
  const AppointmentTile({super.key, required this.appointment});

  final Appointment appointment;

  @override
  Widget build(BuildContext context) {
    final String? scheduledAt = appointment.scheduledAt;
    final String dateLabel = scheduledAt == null
        ? ''
        : formatThaiDateTime(scheduledAt);
    final String? facility = appointment.facility;
    final String? providerName = appointment.providerName;

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
            crossAxisAlignment: CrossAxisAlignment.start,
            children: <Widget>[
              Expanded(child: Text(appointment.title, style: AppType.title)),
              const SizedBox(width: AppSpacing.sm),
              StatusChip(
                label: appointment.statusLabel,
                tone: _statusTone(appointment.status),
              ),
            ],
          ),
          const SizedBox(height: AppSpacing.sm),
          Text(
            dateLabel.isEmpty ? 'ยังไม่ระบุวันเวลา' : dateLabel,
            style: AppType.bodySecondary,
          ),
          if (facility != null && facility.isNotEmpty) ...<Widget>[
            const SizedBox(height: AppSpacing.xs),
            Text(facility, style: AppType.caption),
          ],
          if (providerName != null && providerName.isNotEmpty) ...<Widget>[
            const SizedBox(height: AppSpacing.xs),
            Text(providerName, style: AppType.caption),
          ],
        ],
      ),
    );
  }

  static AppChipTone _statusTone(String status) {
    return switch (status) {
      'attended' => AppChipTone.success,
      'rescheduled' || 'needs_followup' => AppChipTone.warning,
      'confirmed' || 'planned' => AppChipTone.info,
      _ => AppChipTone.neutral,
    };
  }
}
