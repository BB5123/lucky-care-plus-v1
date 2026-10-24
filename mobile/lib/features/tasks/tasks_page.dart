import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/network/api_failure.dart';
import '../../design_system/state_views.dart';
import '../../design_system/tokens.dart';
import 'task_models.dart';
import 'tasks_controller.dart';
import 'widgets/task_detail_sheet.dart';
import 'widgets/task_tile.dart';

/// `/tasks` — non-terminal tasks grouped overdue → today → later.
class TasksPage extends ConsumerWidget {
  const TasksPage({super.key});

  static const String routePath = '/tasks';

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final AsyncValue<TasksData> state = ref.watch(tasksControllerProvider);

    return Scaffold(
      key: const Key('tasks-page'),
      appBar: AppBar(
        backgroundColor: AppColors.surface,
        surfaceTintColor: AppColors.surface,
        title: const Text('งาน'),
      ),
      body: state.when(
        loading: () => const LoadingState(key: Key('tasks-loading')),
        error: (Object error, StackTrace stackTrace) {
          if (error is ApiFailure && error.isPermissionDenied) {
            return const EmptyState(
              key: Key('tasks-permission-state'),
              icon: Icons.lock_outline,
              title: 'คุณยังไม่มีสิทธิ์ดูรายการงาน',
              message:
                  'หากต้องการดูงานของครอบครัว กรุณาขอสิทธิ์จากเจ้าของข้อมูล',
            );
          }

          return ErrorState(
            key: const Key('tasks-error'),
            message: error is ApiFailure
                ? error.message
                : ApiFailure.genericMessage,
            onRetry: () {
              ref.read(tasksControllerProvider.notifier).retry();
            },
          );
        },
        data: (TasksData value) {
          if (!value.hasWorkspace) {
            return const EmptyState(
              key: Key('tasks-no-workspace'),
              icon: Icons.checklist_rtl,
              title: 'ยังไม่มีครอบครัว',
              message: 'เมื่อมีครอบครัวหรือได้รับคำเชิญ รายการงานจะแสดงที่นี่',
            );
          }

          final List<TaskGroup> groups = groupTasks(
            value.tasks,
            DateTime.now(),
          );

          if (groups.isEmpty) {
            return const EmptyState(
              key: Key('tasks-empty-state'),
              icon: Icons.task_alt,
              title: 'ไม่มีงานค้าง',
              message: 'งานทั้งหมดเรียบร้อยแล้ว',
            );
          }

          return ListView(
            key: const Key('tasks-list'),
            padding: const EdgeInsets.all(AppSpacing.lg),
            children: <Widget>[
              for (final TaskGroup group in groups) ...<Widget>[
                Padding(
                  key: Key('task-group-${group.bucket.name}'),
                  padding: const EdgeInsets.only(
                    top: AppSpacing.sm,
                    bottom: AppSpacing.sm,
                  ),
                  child: Row(
                    children: <Widget>[
                      Expanded(
                        child: Text(
                          group.bucket.label,
                          style: AppType.sectionTitle,
                        ),
                      ),
                      Text(
                        '${group.tasks.length} รายการ',
                        style: AppType.caption,
                      ),
                    ],
                  ),
                ),
                for (final CareTask task in group.tasks) ...<Widget>[
                  TaskTile(
                    key: Key('task-${task.id}'),
                    task: task,
                    onTap: () => TaskDetailSheet.show(context, task),
                  ),
                  const SizedBox(height: AppSpacing.md),
                ],
              ],
            ],
          );
        },
      ),
    );
  }
}
