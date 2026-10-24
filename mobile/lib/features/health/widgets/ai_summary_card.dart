import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/ai/ai_models.dart';
import '../../../core/ai/ai_repository.dart';
import '../../../core/network/api_failure.dart';
import '../../../core/workspace/current_workspace.dart';
import '../../../design_system/tokens.dart';

/// "สรุปแนวโน้มด้วย AI" for one profile.
///
/// Click-to-generate: the first call sends the profile's 30-day context only
/// after the user presses the button (AIG-004). Every provider error is mapped
/// to a Thai sentence; the server disclaimer is always shown with the draft.
class AiSummaryCard extends ConsumerStatefulWidget {
  const AiSummaryCard({super.key, required this.context});

  final WorkspaceContext context;

  @override
  ConsumerState<AiSummaryCard> createState() => _AiSummaryCardState();
}

class _AiSummaryCardState extends ConsumerState<AiSummaryCard> {
  bool _loading = false;
  AiSummary? _summary;
  String? _error;

  Future<void> _generate() async {
    if (_loading) {
      return;
    }

    setState(() {
      _loading = true;
      _error = null;
      _summary = null;
    });

    try {
      final AiSummary summary = await ref
          .read(aiRepositoryProvider)
          .summarize(widget.context);

      if (mounted) {
        setState(() => _summary = summary);
      }
    } on ApiFailure catch (failure) {
      if (mounted) {
        setState(() => _error = _message(failure));
      }
    } catch (_) {
      if (mounted) {
        setState(() => _error = ApiFailure.genericMessage);
      }
    } finally {
      if (mounted) {
        setState(() => _loading = false);
      }
    }
  }

  String _message(ApiFailure failure) {
    return switch (failure.code) {
      'ai_disabled' => 'ฟีเจอร์ AI ยังไม่เปิดใช้งานในระบบนี้',
      'no_consent' => 'ต้องได้รับความยินยอมสำหรับการประมวลผลด้วย AI ก่อนใช้ฟีเจอร์นี้',
      'ai_quota_exceeded' => 'คุณใช้ AI ครบจำนวนที่กำหนดในวันนี้แล้ว กรุณาลองใหม่พรุ่งนี้',
      'forbidden' => 'คุณยังไม่มีสิทธิ์ใช้ AI กับข้อมูลนี้',
      _ when failure.status == 503 => 'ระบบ AI ไม่พร้อมใช้งานชั่วคราว กรุณาลองใหม่ภายหลัง',
      _ => failure.message,
    };
  }

  @override
  Widget build(BuildContext context) {
    final AiSummary? summary = _summary;

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
          Text('สรุปแนวโน้มด้วย AI', style: AppType.sectionTitle),
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
              onPressed: _loading ? null : _generate,
              child: Text(_loading ? 'กำลังสรุป...' : 'สร้างสรุป'),
            ),
          ),
          if (_loading) ...<Widget>[
            const SizedBox(height: AppSpacing.md),
            const LinearProgressIndicator(),
          ],
          if (_error != null) ...<Widget>[
            const SizedBox(height: AppSpacing.md),
            Text(
              _error!,
              key: const Key('ai-summary-error'),
              style: AppType.body.copyWith(color: AppColors.danger),
            ),
          ],
          if (summary != null) ...<Widget>[
            const SizedBox(height: AppSpacing.md),
            Text(
              summary.summary,
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
            Text(
              'อ้างอิง ${summary.recordRefs} รายการ · '
              'เหลือ ${summary.remaining} ครั้งวันนี้',
              style: AppType.caption,
            ),
          ],
        ],
      ),
    );
  }
}
