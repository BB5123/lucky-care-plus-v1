import 'package:flutter/material.dart';

import '../../../core/format/thai_date.dart';
import '../../../design_system/tokens.dart';

/// The one calm card that replaces both AI surfaces when the server answers
/// `503 ai_disabled` (`AI_ENABLED=false`).
///
/// Deliberately not an error: no danger colour, no retry, no icon that implies
/// something broke. The manual paths (บันทึกค่า, ยา) stay exactly as they were.
class AiUnavailableCard extends StatelessWidget {
  const AiUnavailableCard({super.key});

  @override
  Widget build(BuildContext context) {
    return Container(
      key: const Key('ai-unavailable-card'),
      width: double.infinity,
      padding: const EdgeInsets.all(AppSpacing.lg),
      decoration: BoxDecoration(
        color: AppColors.infoSurface,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(color: AppColors.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Row(
            children: <Widget>[
              const Icon(
                Icons.auto_awesome_outlined,
                color: AppColors.info,
                size: AppSpacing.xl,
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: Text(
                  'ผู้ช่วย AI ยังไม่เปิดใช้งาน',
                  style: AppType.sectionTitle,
                ),
              ),
            ],
          ),
          const SizedBox(height: AppSpacing.sm),
          Text(
            'ฟีเจอร์ AI ยังไม่เปิดใช้งานในระบบนี้ ระหว่างนี้คุณยังบันทึกค่า '
            'และดูข้อมูลยาได้ตามปกติ',
            style: AppType.bodySecondary,
          ),
        ],
      ),
    );
  }
}

/// "Today's limit is used up" line, shared by both surfaces.
///
/// Muted by design: nothing is broken, the counter simply rolls over. The
/// caller disables its input until then instead of showing an error banner.
class AiQuotaNotice extends StatelessWidget {
  const AiQuotaNotice({super.key, this.resetAt});

  /// When the daily counter resets; falls back to tomorrow.
  final DateTime? resetAt;

  @override
  Widget build(BuildContext context) {
    return Text(
      aiQuotaMessage(resetAt),
      key: const Key('ai-quota-line'),
      style: AppType.caption,
    );
  }
}

/// `คุณใช้ AI ครบจำนวนที่กำหนดในวันนี้แล้ว · ใช้ได้อีกครั้งพรุ่งนี้ (26 ต.ค. 2569)`
String aiQuotaMessage(DateTime? resetAt) {
  final DateTime reset =
      resetAt ?? DateTime.now().add(const Duration(days: 1));
  final String date = formatThaiDate(reset.toIso8601String());
  final String suffix = date.isEmpty ? '' : ' ($date)';

  return 'คุณใช้ AI ครบจำนวนที่กำหนดในวันนี้แล้ว · '
      'ใช้ได้อีกครั้งพรุ่งนี้$suffix';
}
