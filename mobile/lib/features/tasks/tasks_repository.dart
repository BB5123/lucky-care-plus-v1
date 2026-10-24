import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/network/api_client.dart';
import '../../core/network/api_failure.dart';
import '../../core/network/json_read.dart';
import '../../core/network/network_providers.dart';
import '../../core/workspace/current_workspace.dart';
import 'task_models.dart';

/// Workspace-scoped care-task reads and the three transitions this slice
/// exposes. Every mutation sends `If-Match: <version>`; the server owns the
/// state machine and answers 409/412 itself.
class TasksRepository {
  TasksRepository(this._api);

  final ApiClient _api;

  Future<List<CareTask>> list(WorkspaceContext context) async {
    final Map<String, dynamic> body = await _api.get(
      _tasksPath(context),
      query: <String, dynamic>{'limit': 100},
    );

    return readList(body['data'])
        .map((Object? entry) => CareTask.fromJson(readMap(entry)))
        .where((CareTask task) => task.id.isNotEmpty)
        .toList(growable: false);
  }

  Future<CareTask> act({
    required WorkspaceContext context,
    required String taskId,
    required TaskAction action,
    required int version,
    String? outcome,
  }) async {
    final Map<String, dynamic> body = await _api.post(
      '${_tasksPath(context)}/$taskId/${action.path}',
      data: outcome == null ? null : <String, dynamic>{'outcome': outcome},
      headers: <String, String>{'If-Match': version.toString()},
    );

    return CareTask.fromJson(readMap(body['data']));
  }

  String _tasksPath(WorkspaceContext context) {
    final String? workspaceId = context.workspaceId;

    if (workspaceId == null || workspaceId.isEmpty) {
      throw const ApiFailure(
        status: 404,
        code: 'out_of_scope',
        message: 'ไม่พบข้อมูลที่ร้องขอ',
      );
    }

    return '/workspaces/$workspaceId/care-tasks';
  }
}

final Provider<TasksRepository> tasksRepositoryProvider =
    Provider<TasksRepository>(
      (Ref ref) => TasksRepository(ref.watch(apiClientProvider)),
    );
