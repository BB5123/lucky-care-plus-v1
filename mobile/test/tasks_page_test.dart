import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:lucky_care/core/network/api_failure.dart';
import 'package:lucky_care/design_system/app_theme.dart';
import 'package:lucky_care/features/tasks/task_models.dart';
import 'package:lucky_care/features/tasks/tasks_controller.dart';
import 'package:lucky_care/features/tasks/tasks_page.dart';

import 'support/tab_stubs.dart';

class _ConflictTasksController extends TasksController {
  _ConflictTasksController(this.task);

  final CareTask task;

  @override
  Future<TasksData> build() async {
    return TasksData(hasWorkspace: true, tasks: <CareTask>[task]);
  }

  @override
  Future<void> run(CareTask task, TaskAction action, {String? outcome}) async {
    throw const ApiFailure(
      status: 409,
      code: 'domain_rule',
      message: 'งานนี้ถูกปิดไปแล้ว',
    );
  }
}

Widget _harness(TasksController controller) {
  return ProviderScope(
    overrides: [tasksControllerProvider.overrideWith(() => controller)],
    child: MaterialApp(theme: buildAppTheme(), home: const TasksPage()),
  );
}

CareTask _task({
  required String id,
  required String title,
  required String tier,
  required DateTime dueAt,
  int version = 1,
}) {
  return CareTask(
    id: id,
    title: title,
    tier: tier,
    status: 'in_progress',
    dueAt: dueAt.toIso8601String(),
    version: version,
  );
}

void main() {
  test(
    'D2-T05 non-terminal tasks group into overdue, today and later in order',
    () {
      final DateTime now = DateTime(2026, 10, 24, 12);
      final List<CareTask> tasks = <CareTask>[
        _task(
          id: 'later',
          title: 'งานภายหลัง',
          tier: 'routine',
          dueAt: DateTime(2026, 10, 26, 9),
        ),
        _task(
          id: 'overdue',
          title: 'งานเกินกำหนด',
          tier: 'delegated',
          dueAt: DateTime(2026, 10, 23, 9),
        ),
        _task(
          id: 'today',
          title: 'งานวันนี้',
          tier: 'clinical',
          dueAt: DateTime(2026, 10, 24, 18),
        ),
        CareTask(
          id: 'done',
          title: 'งานที่เสร็จแล้ว',
          tier: 'routine',
          status: 'completed',
          dueAt: DateTime(2026, 10, 24, 10).toIso8601String(),
        ),
      ];

      final List<TaskGroup> groups = groupTasks(tasks, now);

      expect(
        groups.map((TaskGroup group) => group.bucket).toList(),
        <TaskBucket>[TaskBucket.overdue, TaskBucket.today, TaskBucket.later],
      );
      expect(
        groups.map(
          (TaskGroup group) =>
              group.tasks.map((CareTask task) => task.id).toList(),
        ),
        <List<String>>[
          <String>['overdue'],
          <String>['today'],
          <String>['later'],
        ],
      );
    },
  );

  testWidgets(
    'D2-T06 a clinical task keeps complete disabled with the evidence reason',
    (WidgetTester tester) async {
      final CareTask clinical = _task(
        id: 'clinical-1',
        title: 'ตรวจค่าน้ำตาล',
        tier: 'clinical',
        dueAt: DateTime.now().add(const Duration(days: 1)),
        version: 3,
      );

      await tester.pumpWidget(
        _harness(
          StubTasksController(
            TasksData(hasWorkspace: true, tasks: <CareTask>[clinical]),
          ),
        ),
      );
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const Key('task-clinical-1')));
      await tester.pumpAndSettle();

      final FilledButton button = tester.widget<FilledButton>(
        find.byKey(const Key('task-complete-button')),
      );

      expect(button.onPressed, isNull);
      expect(find.text('ต้องแนบหลักฐานก่อน'), findsOneWidget);
    },
  );

  testWidgets('D2-T07 a 409 from a task action shows the server Thai message', (
    WidgetTester tester,
  ) async {
    final CareTask routine = _task(
      id: 'routine-1',
      title: 'กินยาหลังอาหาร',
      tier: 'routine',
      dueAt: DateTime.now().add(const Duration(days: 1)),
      version: 4,
    );

    await tester.pumpWidget(_harness(_ConflictTasksController(routine)));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('task-routine-1')));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('task-complete-done')));
    await tester.pumpAndSettle();

    expect(find.text('งานนี้ถูกปิดไปแล้ว'), findsOneWidget);
    expect(find.byKey(const Key('task-action-error')), findsOneWidget);
  });
}
