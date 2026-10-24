import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart' show Override;
import 'package:flutter_test/flutter_test.dart';
import 'package:lucky_care/design_system/app_theme.dart';
import 'package:lucky_care/features/tasks/task_models.dart';
import 'package:lucky_care/features/tasks/tasks_controller.dart';
import 'package:lucky_care/features/tasks/widgets/task_detail_sheet.dart';

import 'support/tab_stubs.dart';

/// FLG-T06 / FLG-T07 — the server flag re-opens the clinical complete action
/// after a restart without ever leaking (or needing) the document id.
CareTask _clinicalTask({required bool? hasPhotoEvidence}) {
  final Map<String, dynamic> json = <String, dynamic>{
    'id': 'clinical-flag-1',
    'title': 'ตรวจค่าน้ำตาล',
    'tier': 'clinical',
    'status': 'in_progress',
    'profile_id': 'profile-1',
    'evidence_policy': 'photo',
    'version': 3,
    'due_at': DateTime.now().add(const Duration(days: 1)).toIso8601String(),
  };

  if (hasPhotoEvidence != null) {
    json['has_photo_evidence'] = hasPhotoEvidence;
  }

  return CareTask.fromJson(json);
}

Future<void> _pumpSheet(WidgetTester tester, CareTask task) async {
  await tester.pumpWidget(
    ProviderScope(
      overrides: <Override>[
        tasksControllerProvider.overrideWith(
          () => StubTasksController(
            TasksData(hasWorkspace: true, tasks: <CareTask>[task]),
          ),
        ),
      ],
      child: MaterialApp(
        theme: buildAppTheme(),
        home: Scaffold(body: TaskDetailSheet(task: task)),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

FilledButton _completeButton(WidgetTester tester) {
  return tester.widget<FilledButton>(
    find.byKey(const Key('task-complete-button')),
  );
}

void main() {
  testWidgets(
    'FLG-T06 has_photo_evidence true enables complete on a fresh session',
    (WidgetTester tester) async {
      final CareTask task = _clinicalTask(hasPhotoEvidence: true);

      await _pumpSheet(tester, task);

      expect(_completeButton(tester).onPressed, isNotNull);
      expect(find.byKey(const Key('task-evidence-reason')), findsNothing);
      expect(find.text('แนบหลักฐานแล้ว'), findsOneWidget);

      // The server told us a photo exists, not which document it is.
      expect(find.byKey(const Key('task-open-file')), findsNothing);
    },
  );

  testWidgets(
    'FLG-T07 has_photo_evidence false or absent keeps complete disabled',
    (WidgetTester tester) async {
      for (final bool? flag in <bool?>[false, null]) {
        final CareTask task = _clinicalTask(hasPhotoEvidence: flag);

        await _pumpSheet(tester, task);

        expect(task.hasPhotoEvidence, isFalse);
        expect(_completeButton(tester).onPressed, isNull);
        expect(find.byKey(const Key('task-evidence-reason')), findsOneWidget);
        expect(find.text('ต้องแนบหลักฐานก่อน'), findsOneWidget);
      }
    },
  );
}
