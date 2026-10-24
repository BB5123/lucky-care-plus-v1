import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/ai/ai_models.dart';
import '../../../core/format/thai_date.dart';
import '../../../core/network/api_failure.dart';
import '../../../core/workspace/current_workspace.dart';
import '../../../design_system/tokens.dart';
import '../ai_controller.dart';
import 'ai_state_views.dart';

/// "ถามเรื่องสุขภาพ" against the government-source corpus.
///
/// The answer arrives already cited with `[A]`/`[B]` markers; the card lists
/// each source with the marker, title, publisher and date, and flags any source
/// that has not passed review. The input is a single line with a send action.
/// No document id and no patient value is ever rendered.
class KnowledgeAskCard extends ConsumerStatefulWidget {
  const KnowledgeAskCard({super.key, required this.context});

  final WorkspaceContext context;

  @override
  ConsumerState<KnowledgeAskCard> createState() => _KnowledgeAskCardState();
}

class _KnowledgeAskCardState extends ConsumerState<KnowledgeAskCard> {
  static const int _maxLength = 500;

  final TextEditingController _controller = TextEditingController();

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  Future<void> _ask() async {
    final String question = _controller.text.trim();

    if (question.isEmpty || ref.read(aiAnswerControllerProvider).isLoading) {
      return;
    }

    await ref
        .read(aiAnswerControllerProvider.notifier)
        .ask(widget.context, question);
  }

  /// Server sentences for the states that are not modelled as typed outcomes.
  String _message(ApiFailure failure) {
    return switch (failure.code) {
      'no_consent' => 'ต้องได้รับความยินยอมสำหรับการประมวลผลด้วย AI ก่อนใช้ฟีเจอร์นี้',
      'forbidden' => 'คุณยังไม่มีสิทธิ์ใช้ AI กับข้อมูลนี้',
      'validation' => 'กรุณาพิมพ์คำถามก่อนส่ง',
      _ when failure.status == 403 || failure.status == 404 =>
        'คุณยังไม่มีสิทธิ์ใช้ AI กับข้อมูลนี้',
      _ => 'ตอบคำถามไม่สำเร็จ กรุณาลองใหม่ภายหลัง',
    };
  }

  @override
  Widget build(BuildContext context) {
    final AsyncValue<KnowledgeAnswer?> state = ref.watch(
      aiAnswerControllerProvider,
    );

    if (state.error is AiUnavailable) {
      return const AiUnavailableCard();
    }

    final KnowledgeAnswer? answer = state.value;
    final Object? error = state.error;
    final bool loading = state.isLoading;
    final AiQuotaExceeded? quota = error is AiQuotaExceeded ? error : null;
    final bool inputEnabled = !loading && quota == null;

    return Container(
      key: const Key('knowledge-ask-card'),
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
          Text('ถามเรื่องสุขภาพ', style: AppType.sectionTitle),
          const SizedBox(height: AppSpacing.xs),
          Text(
            'ตอบจากเอกสารของหน่วยงานราชการที่อ้างอิงไว้ เป็นความรู้ทั่วไป '
            'ไม่ใช่การวินิจฉัยหรือคำแนะนำการรักษาเฉพาะบุคคล',
            style: AppType.bodySecondary,
          ),
          const SizedBox(height: AppSpacing.md),
          TextField(
            key: const Key('knowledge-question'),
            controller: _controller,
            maxLength: _maxLength,
            maxLines: 1,
            enabled: inputEnabled,
            textInputAction: TextInputAction.send,
            onSubmitted: (_) => _ask(),
            onChanged: (_) => setState(() {}),
            decoration: const InputDecoration(
              hintText: 'เช่น ไข้หวัดใหญ่ป้องกันอย่างไร',
              border: OutlineInputBorder(),
              isDense: true,
            ),
          ),
          const SizedBox(height: AppSpacing.sm),
          SizedBox(
            width: double.infinity,
            child: FilledButton(
              key: const Key('knowledge-ask-submit'),
              onPressed:
                  !inputEnabled || _controller.text.trim().isEmpty
                  ? null
                  : _ask,
              child: Text(loading ? 'กำลังค้นหา...' : 'ถาม'),
            ),
          ),
          if (loading) ...<Widget>[
            const SizedBox(height: AppSpacing.md),
            const LinearProgressIndicator(),
            const SizedBox(height: AppSpacing.xs),
            Text(
              'กำลังค้นฐานความรู้...',
              key: const Key('knowledge-loading'),
              style: AppType.caption,
            ),
          ],
          if (quota != null) ...<Widget>[
            const SizedBox(height: AppSpacing.md),
            AiQuotaNotice(
              key: const Key('knowledge-quota'),
              resetAt: quota.resetAt,
            ),
          ],
          if (error is AiNoMatch) ...<Widget>[
            const SizedBox(height: AppSpacing.md),
            Text(
              error.message,
              key: const Key('knowledge-empty'),
              style: AppType.caption,
            ),
          ],
          if (error is ApiFailure) ...<Widget>[
            const SizedBox(height: AppSpacing.md),
            Text(
              _message(error),
              key: const Key('knowledge-ask-error'),
              style: AppType.body.copyWith(color: AppColors.danger),
            ),
          ],
          if (answer != null) ...<Widget>[
            const SizedBox(height: AppSpacing.md),
            Text(
              answer.answer,
              key: const Key('knowledge-answer'),
              style: AppType.body,
            ),
            const SizedBox(height: AppSpacing.md),
            Text(
              answer.disclaimer,
              key: const Key('knowledge-disclaimer'),
              style: AppType.caption,
            ),
            const SizedBox(height: AppSpacing.sm),
            Text(
              'อ้างอิง ${answer.sources.length} แหล่ง · '
              'เหลือ ${answer.remaining} ครั้งวันนี้',
              style: AppType.caption,
            ),
            const SizedBox(height: AppSpacing.sm),
            for (final KnowledgeSource source in answer.sources)
              _SourceRow(source: source),
          ],
        ],
      ),
    );
  }
}

/// One cited source: marker, title, publisher, date, review status.
class _SourceRow extends StatelessWidget {
  const _SourceRow({required this.source});

  final KnowledgeSource source;

  @override
  Widget build(BuildContext context) {
    final String date = formatThaiDate(source.dateValue);
    final String meta = date.isEmpty
        ? source.publisher
        : '${source.publisher} · $date';

    return Padding(
      key: Key('knowledge-source-${source.label}'),
      padding: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Text(
            '[${source.label}] ${source.title}',
            style: AppType.label.copyWith(color: AppColors.info),
          ),
          if (meta.isNotEmpty)
            Text(meta, style: AppType.caption),
          if (source.isUnreviewed)
            Text(
              'ยังไม่ผ่านการตรวจ',
              key: Key('knowledge-source-unreviewed-${source.label}'),
              style: AppType.caption.copyWith(color: AppColors.warning),
            ),
          if (source.url.isNotEmpty)
            SelectableText(source.url, style: AppType.caption),
        ],
      ),
    );
  }
}
