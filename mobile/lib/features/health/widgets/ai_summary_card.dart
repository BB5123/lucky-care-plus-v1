import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/ai/ai_models.dart';
import '../../../core/workspace/current_workspace.dart';
import '../../../design_system/tokens.dart';
import '../ai_controller.dart';
import 'ai_state_views.dart';

/// "สรุปภาพรวม" for one profile.
///
/// Click-to-generate: no health context leaves the device until the button is
/// pressed (AIG-004). The server's disclaimer is always shown with the draft,
/// the reference count is the only thing the expander reveals, and the record
/// ids were dropped in [AiSummary.fromJson] so no id can be rendered here.
class AiSummaryCard extends ConsumerWidget {
  const AiSummaryCard({super.key, required this.context});

  final WorkspaceContext context;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final AsyncValue<AiSummary?> state = ref.watch(aiSummaryControllerProvider);

    if (state.error is AiUnavailable) {
      return const AiUnavailableCard();
    }

    final AiSummary? summary = state.value;
    final Object? error = state.error;
    final bool loading = state.isLoading;
    final AiQuotaExceeded? quota = error is AiQuotaExceeded ? error : null;

    return Container(
      key: const Key('ai-summary-card'),
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
          Text('สรุปภาพรวม', style: AppType.sectionTitle),
          const SizedBox(height: AppSpacing.xs),
          Text(
            'AI จะอ่านข้อมูลที่บันทึกไว้ใน 30 วันล่าสุดแล้วสรุปให้อ่านเร็วขึ้น '
            'ข้อความนี้ไม่ใช่การวินิจฉัยหรือคำแนะนำการรักษา',
            style: AppType.bodySecondary,
          ),
          const SizedBox(height: AppSpacing.md),
          SizedBox(
            width: double.infinity,
            child: FilledButton(
              key: const Key('ai-summary-generate'),
              onPressed: loading || quota != null
                  ? null
                  : () {
                      ref
                          .read(aiSummaryControllerProvider.notifier)
                          .generate(this.context);
                    },
              child: Text(
                loading
                    ? 'กำลังสรุป...'
                    : summary == null
                    ? 'สร้างสรุป'
                    : 'สร้างสรุปใหม่',
              ),
            ),
          ),
          if (loading) ...<Widget>[
            const SizedBox(height: AppSpacing.md),
            const LinearProgressIndicator(),
            const SizedBox(height: AppSpacing.xs),
            Text(
              'กำลังให้ AI สรุปข้อมูล...',
              key: const Key('ai-summary-loading'),
              style: AppType.caption,
            ),
          ],
          if (quota != null) ...<Widget>[
            const SizedBox(height: AppSpacing.md),
            AiQuotaNotice(
              key: const Key('ai-summary-quota'),
              resetAt: quota.resetAt,
            ),
          ],
          if (error != null && quota == null) ...<Widget>[
            const SizedBox(height: AppSpacing.md),
            Text(
              'สร้างสรุปไม่สำเร็จ กรุณาลองใหม่ภายหลัง',
              key: const Key('ai-summary-error'),
              style: AppType.body.copyWith(color: AppColors.danger),
            ),
            const SizedBox(height: AppSpacing.sm),
            Align(
              alignment: Alignment.centerLeft,
              child: TextButton(
                key: const Key('ai-summary-retry'),
                onPressed: () {
                  ref
                      .read(aiSummaryControllerProvider.notifier)
                      .generate(this.context);
                },
                child: const Text('ลองอีกครั้ง'),
              ),
            ),
          ],
          if (summary != null) ...<Widget>[
            const SizedBox(height: AppSpacing.md),
            Text(
              summary.text,
              key: const Key('ai-summary-text'),
              style: AppType.body,
            ),
            const SizedBox(height: AppSpacing.md),
            Text(
              summary.disclaimer,
              key: const Key('ai-summary-disclaimer'),
              style: AppType.caption,
            ),
            const SizedBox(height: AppSpacing.sm),
            ExpansionTile(
              key: const Key('ai-summary-refs'),
              title: Text(
                'อ้างอิง ${summary.recordRefs} รายการ',
                style: AppType.label,
              ),
              tilePadding: EdgeInsets.zero,
              childrenPadding: const EdgeInsets.only(bottom: AppSpacing.sm),
              dense: true,
              shape: const Border(),
              collapsedShape: const Border(),
              iconColor: AppColors.textSecondary,
              collapsedIconColor: AppColors.textSecondary,
              textColor: AppColors.textPrimary,
              children: <Widget>[
                Align(
                  alignment: Alignment.centerLeft,
                  child: Text(
                    'สรุปนี้สร้างจากบันทึก ${summary.recordRefs} รายการ '
                    'โดยไม่แสดงรายละเอียดของข้อมูลสุขภาพ',
                    key: const Key('ai-summary-refs-detail'),
                    style: AppType.caption,
                  ),
                ),
              ],
            ),
            Text(
              'เหลือ ${summary.remaining} ครั้งวันนี้',
              style: AppType.caption,
            ),
          ],
        ],
      ),
    );
  }
}
