import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/ai/ai_models.dart';
import '../../core/ai/ai_repository.dart';
import '../../core/workspace/current_workspace.dart';

/// State machine shared by both AI surfaces, expressed as [AsyncValue]:
///
/// | screen state | [AsyncValue] |
/// |---|---|
/// | empty (not asked yet) | `AsyncData(null)` |
/// | loading | `AsyncLoading` |
/// | ready | `AsyncData(result)` |
/// | unavailable (`AI_ENABLED=false`) | `AsyncError(AiUnavailable)` |
/// | quota reached | `AsyncError(AiQuotaExceeded)` |
/// | no corpus match | `AsyncError(AiNoMatch)` |
/// | error | `AsyncError(ApiFailure | other)` |
///
/// The distinction matters: only the last row may look like an error with a
/// retry. Unavailable and quota are calm, explanatory states
/// (`.ai/MOBILE_DESIGN.md` §5/§9).
///
/// Neither controller calls the API on build: the gateway is click-to-generate
/// (AIG-004), so an empty AI tab costs nothing and sends no health context.

/// "สรุปภาพรวม" for one profile.
class AiSummaryController extends AsyncNotifier<AiSummary?> {
  /// Synchronous so the first frame is the empty state, not a loading state:
  /// the card must be tappable immediately (the UI test taps on frame one).
  @override
  AiSummary? build() => null;

  Future<void> generate(
    WorkspaceContext context, {
    DateTime? from,
    DateTime? to,
  }) async {
    state = const AsyncLoading<AiSummary?>();
    final AsyncValue<AiSummary?> next = await AsyncValue.guard<AiSummary?>(
      () => ref
          .read(aiRepositoryProvider)
          .summarize(context, from: from, to: to),
    );

    if (!ref.mounted) {
      return;
    }

    state = next;
  }

  /// Forgets the last draft (used when the profile changes).
  void clear() {
    state = const AsyncData<AiSummary?>(null);
  }
}

/// "ถามเรื่องสุขภาพ" against the cited corpus.
class AiAnswerController extends AsyncNotifier<KnowledgeAnswer?> {
  @override
  KnowledgeAnswer? build() => null;

  Future<void> ask(WorkspaceContext context, String question) async {
    state = const AsyncLoading<KnowledgeAnswer?>();
    final AsyncValue<KnowledgeAnswer?> next =
        await AsyncValue.guard<KnowledgeAnswer?>(
          () => ref.read(aiRepositoryProvider).askKnowledge(context, question),
        );

    if (!ref.mounted) {
      return;
    }

    state = next;
  }

  /// Forgets the last answer (used when the profile changes).
  void clear() {
    state = const AsyncData<KnowledgeAnswer?>(null);
  }
}

final AsyncNotifierProvider<AiSummaryController, AiSummary?>
aiSummaryControllerProvider =
    AsyncNotifierProvider<AiSummaryController, AiSummary?>(
      AiSummaryController.new,
      isAutoDispose: true,
      retry: (int retryCount, Object error) => null,
    );

final AsyncNotifierProvider<AiAnswerController, KnowledgeAnswer?>
aiAnswerControllerProvider =
    AsyncNotifierProvider<AiAnswerController, KnowledgeAnswer?>(
      AiAnswerController.new,
      isAutoDispose: true,
      retry: (int retryCount, Object error) => null,
    );
