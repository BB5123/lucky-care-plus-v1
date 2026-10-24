import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/workspace/current_workspace.dart';
import 'task_models.dart';
import 'tasks_repository.dart';

/// Loads the workspace's care tasks and applies one transition at a time.
class TasksController extends AsyncNotifier<TasksData> {
  /// Sentinel stored when the server says a photo exists but the client does
  /// not know (and must not learn) its document id.
  static const String serverEvidenceSentinel = 'server';

  /// `taskId -> documentId` for photo evidence attached in this session, or
  /// [serverEvidenceSentinel] when the task payload's `has_photo_evidence`
  /// flag proves a photo already exists.
  ///
  /// Reading evidence needs a separate, full-access permission, so the sheet
  /// mirrors only what this session proved or what the flag safely states. A
  /// fresh session therefore opens with complete enabled when the server says
  /// a photo exists; the complete transition is always re-checked server-side.
  final Map<String, String> _photoDocuments = <String, String>{};

  String? photoDocumentId(String taskId) => _photoDocuments[taskId];

  void markPhotoEvidence(String taskId, String documentId) {
    _photoDocuments[taskId] = documentId;
  }

  /// Seeds the session map from the server flag without ever inventing a
  /// document id. A real attachment made in this session is kept.
  void _seedFromServer(Iterable<CareTask> tasks) {
    for (final CareTask task in tasks) {
      if (task.hasPhotoEvidence) {
        _photoDocuments.putIfAbsent(task.id, () => serverEvidenceSentinel);
      }
    }
  }

  @override
  Future<TasksData> build() async {
    final TasksRepository repository = ref.watch(tasksRepositoryProvider);
    final WorkspaceContext context = await ref.watch(
      currentWorkspaceProvider.future,
    );

    if (!context.hasWorkspace) {
      return TasksData.noWorkspace;
    }

    final List<CareTask> tasks = await repository.list(context);
    _seedFromServer(tasks);

    return TasksData(hasWorkspace: true, tasks: tasks);
  }

  void retry() {
    ref.invalidateSelf();
  }

  /// Re-reads the list after a 412 conflict. The guard keeps a failed reload
  /// in the controller's error state instead of throwing into the sheet.
  Future<void> refresh() async {
    state = const AsyncLoading<TasksData>();
    state = await AsyncValue.guard<TasksData>(() async {
      final TasksRepository repository = ref.read(tasksRepositoryProvider);
      final WorkspaceContext context = await ref.read(
        currentWorkspaceProvider.future,
      );

      if (!context.hasWorkspace) {
        return TasksData.noWorkspace;
      }

      final List<CareTask> tasks = await repository.list(context);
      _seedFromServer(tasks);

      return TasksData(hasWorkspace: true, tasks: tasks);
    });
  }

  /// Sends one transition. A 409/412 [ApiFailure] is rethrown so the detail
  /// sheet can render the server's Thai message or the conflict action.
  Future<void> run(CareTask task, TaskAction action, {String? outcome}) async {
    final WorkspaceContext context = await ref.read(
      currentWorkspaceProvider.future,
    );
    final CareTask updated = await ref
        .read(tasksRepositoryProvider)
        .act(
          context: context,
          taskId: task.id,
          action: action,
          version: task.version,
          outcome: outcome,
        );
    final TasksData? current = state.value;

    if (current == null) {
      return;
    }

    final List<CareTask> next = List<CareTask>.of(current.tasks);
    final int index = next.indexWhere(
      (CareTask entry) => entry.id == updated.id,
    );

    if (updated.isTerminal) {
      if (index >= 0) {
        next.removeAt(index);
      }

      _photoDocuments.remove(updated.id);
    } else if (index >= 0) {
      next[index] = updated;
      _seedFromServer(<CareTask>[updated]);
    } else {
      next.add(updated);
      _seedFromServer(<CareTask>[updated]);
    }

    state = AsyncData<TasksData>(
      TasksData(hasWorkspace: current.hasWorkspace, tasks: next),
    );
  }
}

final AsyncNotifierProvider<TasksController, TasksData>
tasksControllerProvider = AsyncNotifierProvider<TasksController, TasksData>(
  TasksController.new,
  isAutoDispose: true,
  retry: (int retryCount, Object error) => null,
);
