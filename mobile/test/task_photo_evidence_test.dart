import 'dart:async';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart' show Override;
import 'package:flutter_test/flutter_test.dart';
import 'package:lucky_care/core/documents/document_models.dart';
import 'package:lucky_care/core/documents/documents_repository.dart';
import 'package:lucky_care/core/media/photo_source.dart';
import 'package:lucky_care/core/network/api_client.dart';
import 'package:lucky_care/core/workspace/current_workspace.dart';
import 'package:lucky_care/design_system/app_theme.dart';
import 'package:lucky_care/features/tasks/task_models.dart';
import 'package:lucky_care/features/tasks/tasks_controller.dart';
import 'package:lucky_care/features/tasks/tasks_page.dart';
import 'package:lucky_care/features/tasks/tasks_repository.dart';

import 'support/tab_stubs.dart';

final Uint8List _photoBytes = Uint8List.fromList(<int>[1, 2, 3, 4, 5, 6]);

class _FakeTasksRepository extends TasksRepository {
  _FakeTasksRepository() : super(ApiClient());

  List<CareTask> tasks = <CareTask>[];

  int addEvidenceCalls = 0;
  String? lastTaskId;
  String? lastDocumentId;
  int? lastVersion;

  @override
  Future<List<CareTask>> list(WorkspaceContext context) async {
    return List<CareTask>.of(tasks);
  }

  @override
  Future<TaskEvidence> addPhotoEvidence({
    required WorkspaceContext context,
    required String taskId,
    required String documentId,
    required int version,
  }) async {
    addEvidenceCalls++;
    lastTaskId = taskId;
    lastDocumentId = documentId;
    lastVersion = version;

    return TaskEvidence(
      id: 'evidence-$addEvidenceCalls',
      type: 'photo',
      documentId: documentId,
    );
  }
}

class _FakeDocumentsRepository extends DocumentsRepository {
  _FakeDocumentsRepository() : super(ApiClient());

  int uploadCalls = 0;
  int signedUrlCalls = 0;

  String? lastProfileId;
  String? lastFilename;
  String? lastMimeType;
  Uint8List? lastBytes;

  DocumentUploadException? uploadFailure;
  Completer<void>? uploadGate;

  @override
  Future<MedicalDocument> upload({
    required WorkspaceContext context,
    required String profileId,
    required Uint8List bytes,
    required String filename,
    required String mimeType,
    void Function(int percent)? onProgress,
  }) async {
    uploadCalls++;
    lastProfileId = profileId;
    lastFilename = filename;
    lastMimeType = mimeType;
    lastBytes = bytes;
    onProgress?.call(100);

    final Completer<void>? gate = uploadGate;

    if (gate != null) {
      await gate.future;
    }

    final DocumentUploadException? failure = uploadFailure;

    if (failure != null) {
      throw failure;
    }

    return const MedicalDocument(
      id: 'document-1',
      kind: 'photo',
      mimeType: 'image/jpeg',
      sizeBytes: 6,
      status: 'available',
    );
  }

  @override
  Future<DocumentSignedUrl> signedUrl({
    required WorkspaceContext context,
    required String profileId,
    required String documentId,
  }) async {
    signedUrlCalls++;

    return const DocumentSignedUrl(
      url: 'https://files.example/document-1',
      expiresAt: '2026-10-24T10:00:00Z',
    );
  }
}

class _FakePhotoSource implements PhotoSource {
  int pickCalls = 0;
  PhotoOrigin? lastOrigin;
  PickedPhoto? photo;

  @override
  Future<PickedPhoto?> pick(PhotoOrigin origin) async {
    pickCalls++;
    lastOrigin = origin;

    return photo;
  }
}

class _FixedWorkspaceController extends CurrentWorkspaceController {
  @override
  Future<WorkspaceContext> build() async {
    return const WorkspaceContext(workspaceId: 'ws-1');
  }
}

class _Harness {
  _Harness() {
    tasks.tasks = <CareTask>[task];
  }

  final CareTask task = CareTask(
    id: 'clinical-1',
    title: 'ตรวจค่าน้ำตาล',
    tier: 'clinical',
    status: 'in_progress',
    profileId: 'profile-1',
    evidencePolicy: 'photo',
    version: 3,
    dueAt: DateTime.now().add(const Duration(days: 1)).toIso8601String(),
  );

  final _FakeTasksRepository tasks = _FakeTasksRepository();
  final _FakeDocumentsRepository documents = _FakeDocumentsRepository();
  final _FakePhotoSource photos = _FakePhotoSource();

  Widget build() {
    return ProviderScope(
      overrides: <Override>[
        tasksControllerProvider.overrideWith(
          () => StubTasksController(
            TasksData(hasWorkspace: true, tasks: <CareTask>[task]),
          ),
        ),
        tasksRepositoryProvider.overrideWithValue(tasks),
        documentsRepositoryProvider.overrideWithValue(documents),
        photoSourceProvider.overrideWithValue(photos),
        currentWorkspaceProvider.overrideWith(_FixedWorkspaceController.new),
      ],
      child: MaterialApp(theme: buildAppTheme(), home: const TasksPage()),
    );
  }
}

PickedPhoto _pickedPhoto() {
  return PickedPhoto(
    bytes: _photoBytes,
    filename: 'evidence-1712345678901234.jpg',
    mimeType: 'image/jpeg',
  );
}

Future<void> _openSheet(WidgetTester tester, _Harness harness) async {
  await tester.pumpWidget(harness.build());
  await tester.pumpAndSettle();
  await tester.tap(find.byKey(const Key('task-clinical-1')));
  await tester.pumpAndSettle();
}

Future<void> _tap(WidgetTester tester, Key key) async {
  final Finder finder = find.byKey(key);

  await tester.ensureVisible(finder);
  await tester.pumpAndSettle();
  await tester.tap(finder);
  await tester.pumpAndSettle();
}

void main() {
  testWidgets(
    'J-T02 a 422 shows the server Thai sentence and attaches nothing',
    (WidgetTester tester) async {
      final _Harness harness = _Harness();

      harness.photos.photo = _pickedPhoto();
      harness.documents.uploadFailure = const DocumentUploadException(
        status: 422,
        code: 'validation',
        message: 'ไฟล์ต้องมีขนาดไม่เกิน 10485760 ไบต์',
      );

      await _openSheet(tester, harness);
      await _tap(tester, const Key('task-pick-camera'));

      expect(harness.documents.uploadCalls, 1);
      expect(harness.tasks.addEvidenceCalls, 0);
      expect(find.text('ไฟล์ต้องมีขนาดไม่เกิน 10485760 ไบต์'), findsOneWidget);
      expect(find.byKey(const Key('task-photo-error')), findsOneWidget);
      expect(find.byKey(const Key('task-open-file')), findsNothing);
      expect(
        tester
            .widget<FilledButton>(find.byKey(const Key('task-complete-button')))
            .onPressed,
        isNull,
      );
    },
  );

  testWidgets('J-T03 a 503 scan_unavailable explains the scanner outage', (
    WidgetTester tester,
  ) async {
    final _Harness harness = _Harness();

    harness.photos.photo = _pickedPhoto();
    harness.documents.uploadFailure = const DocumentUploadException(
      status: 503,
      code: 'scan_unavailable',
      message: 'ไม่สามารถตรวจสอบไฟล์ได้ในขณะนี้',
    );

    await _openSheet(tester, harness);
    await _tap(tester, const Key('task-pick-camera'));

    expect(harness.tasks.addEvidenceCalls, 0);
    expect(
      find.text('ระบบตรวจไวรัสไม่พร้อมใช้งาน จึงยังอัปโหลดไม่ได้'),
      findsOneWidget,
    );
  });

  testWidgets('J-T04 a successful upload creates photo evidence', (
    WidgetTester tester,
  ) async {
    final _Harness harness = _Harness();

    harness.photos.photo = _pickedPhoto();

    await _openSheet(tester, harness);
    await _tap(tester, const Key('task-pick-camera'));

    expect(harness.documents.uploadCalls, 1);
    expect(harness.documents.lastProfileId, 'profile-1');
    expect(harness.documents.lastFilename, 'evidence-1712345678901234.jpg');
    expect(harness.documents.lastMimeType, 'image/jpeg');
    expect(harness.documents.lastBytes, _photoBytes);

    expect(harness.tasks.addEvidenceCalls, 1);
    expect(harness.tasks.lastTaskId, 'clinical-1');
    expect(harness.tasks.lastDocumentId, 'document-1');
    expect(harness.tasks.lastVersion, 3);
    expect(find.text('evidence-1712345678901234.jpg'), findsOneWidget);
  });

  testWidgets('J-T05 ดูไฟล์ requests a signed URL only on tap', (
    WidgetTester tester,
  ) async {
    final _Harness harness = _Harness();

    harness.photos.photo = _pickedPhoto();

    await _openSheet(tester, harness);
    await _tap(tester, const Key('task-pick-camera'));

    expect(harness.documents.signedUrlCalls, 0);

    await _tap(tester, const Key('task-open-file'));

    expect(harness.documents.signedUrlCalls, 1);

    await _tap(tester, const Key('task-close-file-dialog'));
  });

  testWidgets('J-T06 complete is enabled once a photo evidence row exists', (
    WidgetTester tester,
  ) async {
    final _Harness harness = _Harness();

    harness.photos.photo = _pickedPhoto();

    await _openSheet(tester, harness);

    expect(
      tester
          .widget<FilledButton>(find.byKey(const Key('task-complete-button')))
          .onPressed,
      isNull,
    );
    expect(find.text('ต้องแนบหลักฐานก่อน'), findsOneWidget);

    await _tap(tester, const Key('task-pick-camera'));

    expect(
      tester
          .widget<FilledButton>(find.byKey(const Key('task-complete-button')))
          .onPressed,
      isNotNull,
    );
    expect(find.text('ต้องแนบหลักฐานก่อน'), findsNothing);
  });

  testWidgets('J-T07 the picker is injected and no platform channel is used', (
    WidgetTester tester,
  ) async {
    final _Harness harness = _Harness();

    harness.photos.photo = _pickedPhoto();

    await _openSheet(tester, harness);
    await _tap(tester, const Key('task-pick-camera'));

    expect(harness.photos.pickCalls, 1);
    expect(harness.photos.lastOrigin, PhotoOrigin.camera);
    expect(
      find.text('ไม่สามารถเปิดกล้องหรือคลังภาพได้ กรุณาลองใหม่'),
      findsNothing,
    );
  });

  testWidgets(
    'upload progress is visible and the attach actions are disabled',
    (WidgetTester tester) async {
      final _Harness harness = _Harness();

      harness.photos.photo = _pickedPhoto();
      harness.documents.uploadGate = Completer<void>();

      await _openSheet(tester, harness);

      final Finder camera = find.byKey(const Key('task-pick-camera'));

      await tester.ensureVisible(camera);
      await tester.tap(camera);
      await tester.pump();
      await tester.pump();

      expect(find.byKey(const Key('task-upload-progress')), findsOneWidget);
      expect(find.text('กำลังอัปโหลด 100%'), findsOneWidget);
      expect(tester.widget<OutlinedButton>(camera).onPressed, isNull);
      expect(
        tester
            .widget<OutlinedButton>(find.byKey(const Key('task-pick-gallery')))
            .onPressed,
        isNull,
      );
      expect(harness.tasks.addEvidenceCalls, 0);

      harness.documents.uploadGate!.complete();
      await tester.pumpAndSettle();

      expect(harness.tasks.addEvidenceCalls, 1);
      expect(find.byKey(const Key('task-upload-progress')), findsNothing);
    },
  );

  testWidgets('a routine task keeps its actions and has no attach section', (
    WidgetTester tester,
  ) async {
    final CareTask routine = CareTask(
      id: 'routine-1',
      title: 'กินยาหลังอาหาร',
      tier: 'routine',
      status: 'in_progress',
      profileId: 'profile-1',
      version: 1,
      dueAt: DateTime.now().add(const Duration(days: 1)).toIso8601String(),
    );
    final _FakeTasksRepository tasks = _FakeTasksRepository()
      ..tasks = <CareTask>[routine];
    final _FakeDocumentsRepository documents = _FakeDocumentsRepository();
    final _FakePhotoSource photos = _FakePhotoSource();

    await tester.pumpWidget(
      ProviderScope(
        overrides: <Override>[
          tasksControllerProvider.overrideWith(
            () => StubTasksController(
              TasksData(hasWorkspace: true, tasks: <CareTask>[routine]),
            ),
          ),
          tasksRepositoryProvider.overrideWithValue(tasks),
          documentsRepositoryProvider.overrideWithValue(documents),
          photoSourceProvider.overrideWithValue(photos),
          currentWorkspaceProvider.overrideWith(_FixedWorkspaceController.new),
        ],
        child: MaterialApp(theme: buildAppTheme(), home: const TasksPage()),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('task-routine-1')));
    await tester.pumpAndSettle();

    expect(find.text('แนบรูปหลักฐาน'), findsNothing);
    expect(find.byKey(const Key('task-pick-camera')), findsNothing);
    expect(find.byKey(const Key('task-complete-done')), findsOneWidget);
  });
}
