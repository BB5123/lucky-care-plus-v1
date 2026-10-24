import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/ai/ai_models.dart';
import '../../../core/ai/ai_repository.dart';
import '../../../core/network/api_failure.dart';
import '../../../core/workspace/current_workspace.dart';
import '../../../design_system/tokens.dart';

/// "ถามความรู้สุขภาพ" against the government-source corpus.
///
/// The answer is always cited; the card lists each source with its label and
/// publisher. The URL is selectable (no external-browser dependency) so a
/// caregiver can copy it. Errors map to Thai sentences, including the
/// "no corpus match" case where the server never called a provider.
class KnowledgeAskCard extends ConsumerStatefulWidget {
  const KnowledgeAskCard({super.key, required this.context});

  final WorkspaceContext context;

  @override
  ConsumerState<KnowledgeAskCard> createState() => _KnowledgeAskCardState();
}

class _KnowledgeAskCardState extends ConsumerState<KnowledgeAskCard> {
  static const int _maxLength = 500;

  final TextEditingController _controller = TextEditingController();

  bool _loading = false;
  KnowledgeAnswer? _answer;
  String? _error;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  Future<void> _ask() async {
    final String question = _controller.text.trim();

    if (question.isEmpty || _loading) {
      return;
    }

    setState(() {
      _loading = true;
      _error = null;
      _answer = null;
    });

    try {
      final KnowledgeAnswer answer = await ref
          .read(aiRepositoryProvider)
          .ask(widget.context, question);

      if (mounted) {
        setState(() => _answer = answer);
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
      'knowledge_not_found' =>
        'ไม่พบข้อมูลในฐานความรู้สำหรับคำถามนี้ ลองถามด้วยคำที่เฉพาะเจาะจงขึ้น หรือปรึกษาแพทย์',
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
    final KnowledgeAnswer? answer = _answer;

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
          Text('ถามความรู้สุขภาพ', style: AppType.sectionTitle),
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
            maxLines: 3,
            minLines: 2,
            enabled: !_loading,
            textInputAction: TextInputAction.newline,
            decoration: const InputDecoration(
              hintText: 'เช่น ไข้เลือดออกป้องกันอย่างไร',
              border: OutlineInputBorder(),
            ),
            onChanged: (_) => setState(() {}),
          ),
          const SizedBox(height: AppSpacing.sm),
          SizedBox(
            width: double.infinity,
            child: FilledButton(
              key: const Key('knowledge-ask-submit'),
              onPressed: _loading || _controller.text.trim().isEmpty
                  ? null
                  : _ask,
              child: Text(_loading ? 'กำลังค้นหา...' : 'ถาม'),
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
            for (final KnowledgeSource source in answer.sources) ...<Widget>[
              Text(
                '[${source.label}] ${source.title} — ${source.publisher}',
                style: AppType.caption.copyWith(color: AppColors.info),
              ),
              SelectableText(source.url, style: AppType.caption),
              const SizedBox(height: AppSpacing.xs),
            ],
          ],
        ],
      ),
    );
  }
}
