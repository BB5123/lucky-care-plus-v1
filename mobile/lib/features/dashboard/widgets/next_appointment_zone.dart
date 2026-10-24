import 'package:flutter/material.dart';

import '../../../core/format/thai_date.dart';
import '../../../design_system/tokens.dart';
import '../../../design_system/zone_card.dart';
import '../dashboard_models.dart';

/// Zone 5 — the next appointment, or an explicit empty sentence.
class NextAppointmentZoneWidget extends StatelessWidget {
  const NextAppointmentZoneWidget({super.key, required this.zone});

  static const Key zoneKey = Key('zone-appointment');

  final NextAppointmentZone zone;

  @override
  Widget build(BuildContext context) {
    final String? title = zone.title;
    final String? scheduledAt = zone.scheduledAt;

    if (!zone.hasAppointment) {
      return const ZoneCard(
        title: 'นัดหมายถัดไป',
        icon: Icons.event_available,
        child: Text('ยังไม่มีนัดหมาย', style: AppType.bodySecondary),
      );
    }

    final String dateLabel = formatThaiDateTime(scheduledAt!);

    return ZoneCard(
      title: 'นัดหมายถัดไป',
      icon: Icons.event_available,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Text(title!, style: AppType.title),
          const SizedBox(height: AppSpacing.sm),
          Text(
            dateLabel.isEmpty ? 'ยังไม่ระบุวันเวลา' : dateLabel,
            style: AppType.bodySecondary,
          ),
        ],
      ),
    );
  }
}
