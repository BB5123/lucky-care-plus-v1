import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/ai/ai_models.dart';
import '../../../core/network/api_failure.dart';
import '../../../core/workspace/current_workspace.dart';
import '../../../design_system/state_views.dart';
import '../../../design_system/tokens.dart';
import 'ai_controller.dart';
import 'widgets/ai_state_views.dart';
import 'widgets/ai_summary_card.dart';
import 'widgets/knowledge_ask_card.dart';

/// The "ผู้ช่วย AI" segment of the health tab: a trend summary and the
/// knowledge Q&A.
///
/// The workspace/profile comes from the shared context (N-01); when the actor
/// has no readable profile the section explains that instead of calling the
/// API. AI is disabled by default (`AI_ENABLED=false`), in which case the first
/// attempt reports [AiUnavailable] and both cards are replaced by one calm
/// explanation — never an error banner, never a retry that cannot succeed.
class AiView extends ConsumerWidget {
  const AiView({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final AsyncValue<WorkspaceContext> state = ref.watch(
      currentWorkspaceProvider,
    );
    final bool unavailable =
        ref.watch(aiSummaryControllerProvider).error is AiUnavailable ||
        ref.watch(aiAnswerControllerProvider).error is AiUnavailable;

    return state.when(
      loading: () => const LoadingState(key: Key('ai-loading')),
      error: (Object error, StackTrace stackTrace) {
        return ErrorState(
          key: const Key('ai-error'),
          message: error is ApiFailure
              ? error.message
              : ApiFailure.genericMessage,
          onRetry: () {
            ref.read(currentWorkspaceProvider.notifier).retry();
          },
        );
      },
      data: (WorkspaceContext workspace) {
        if (!workspace.hasProfile) {
          return const EmptyState(
            key: Key('ai-no-profile'),
            icon: Icons.psychology_outlined,
            title: 'ยังไม่มีโปรไฟล์สุขภาพ',
            message: 'เมื่อมีความเชื่อมโยงกับโปรไฟล์สุขภาพ AI จะช่วยสรุปและตอบคำถามได้',
          );
        }

        if (unavailable) {
          return ListView(
            key: const Key('ai-view'),
            padding: const EdgeInsets.all(AppSpacing.lg),
            children: const <Widget>[AiUnavailableCard()],
          );
        }

        return ListView(
          key: const Key('ai-view'),
          padding: const EdgeInsets.all(AppSpacing.lg),
          children: <Widget>[
            AiSummaryCard(context: workspace),
            const SizedBox(height: AppSpacing.md),
            KnowledgeAskCard(context: workspace),
          ],
        );
      },
    );
  }
}
