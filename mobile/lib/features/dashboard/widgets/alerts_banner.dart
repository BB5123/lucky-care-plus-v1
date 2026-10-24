import 'package:flutter/material.dart';

import '../../../design_system/tokens.dart';
import '../dashboard_models.dart';

/// Zone 2 — priority alert banner. Rendered only when there is at least one
/// alert; the payload carries a translation key, never a value (N-02).
class AlertsBanner extends StatelessWidget {
  const AlertsBanner({super.key, required this.items});

  static const Key zoneKey = Key('zone-alerts');

  final List<AlertItem> items;

  bool get _hasCritical =>
      items.any((AlertItem item) => item.isCritical);

  @override
  Widget build(BuildContext context) {
    final bool critical = _hasCritical;
    final Color accent = critical ? AppColors.danger : AppColors.warning;
    final Color background = critical
        ? AppColors.dangerSurface
        : AppColors.warningSurface;

    final List<String> messages = <String>{
      for (final AlertItem item in items) _alertMessage(item.messageKey),
    }.toList(growable: false);

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(AppSpacing.lg),
      decoration: BoxDecoration(
        color: background,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(color: accent),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Icon(
            critical ? Icons.warning_amber_rounded : Icons.info_outline,
            color: accent,
          ),
          const SizedBox(width: AppSpacing.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Text(
                  critical ? 'ต้องดูแลทันที' : 'มีเรื่องที่ควรตรวจสอบ',
                  style: AppType.label.copyWith(
                    fontWeight: FontWeight.w700,
                    color: accent,
                  ),
                ),
                const SizedBox(height: AppSpacing.xs),
                for (final String message in messages.take(3))
                  Padding(
                    padding: const EdgeInsets.only(bottom: AppSpacing.xs),
                    child: Text(message, style: AppType.body),
                  ),
                Text(
                  'ทั้งหมด ${items.length} รายการ',
                  style: AppType.caption,
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  String _alertMessage(String messageKey) {
    return switch (messageKey) {
      'dashboard.alert.vital_critical' => 'มีค่าสุขภาพที่ต้องดูแลทันที',
      'dashboard.alert.vital_high' => 'มีค่าสุขภาพสูงกว่าเกณฑ์',
      'dashboard.alert.vital_low' => 'มีค่าสุขภาพต่ำกว่าเกณฑ์',
      'dashboard.alert.tasks_overdue' => 'มีงานที่เลยกำหนด',
      'dashboard.alert.appointment_soon' => 'มีนัดหมายวันนี้ที่ต้องยืนยัน',
      _ => 'มีรายการที่ต้องตรวจสอบ',
    };
  }
}
