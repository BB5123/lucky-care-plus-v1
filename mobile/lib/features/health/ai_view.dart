import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/api_failure.dart';
import '../../../core/workspace/current_workspace.dart';
import '../../../design_system/state_views.dart';
import '../../../design_system/tokens.dart';
import 'widgets/ai_summary_card.dart';
import 'widgets/knowledge_ask_card.dart';

/// The "AI" section of the health tab: a summary card and the knowledge Q&A.
///
/// The workspace/profile comes from the shared context (N-01); when the actor
/// has no readable profile the section explains that instead of calling the
/// API. AI stays disabled by default, so both cards show the server's
/// ai_disabled sentence until an operator enables the feature.
class AiView extends ConsumerWidget {
  const AiView({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final AsyncValue<WorkspaceContext> state = ref.watch(
      currentWorkspaceProvider,
    );

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
