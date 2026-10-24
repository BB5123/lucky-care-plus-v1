import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart' show Override;
import 'package:flutter_test/flutter_test.dart';
import 'package:lucky_care/core/ai/ai_models.dart';
import 'package:lucky_care/core/ai/ai_repository.dart';
import 'package:lucky_care/core/documents/document_models.dart';
import 'package:lucky_care/core/documents/documents_repository.dart';
import 'package:lucky_care/core/media/photo_source.dart';
import 'package:lucky_care/core/medication/medication_models.dart';
import 'package:lucky_care/core/medication/medication_repository.dart';
import 'package:lucky_care/core/network/api_client.dart';
import 'package:lucky_care/core/network/api_failure.dart';
import 'package:lucky_care/core/profiles/profile.models.dart';
import 'package:lucky_care/core/workspace/current_workspace.dart';
import 'package:lucky_care/design_system/app_theme.dart';
import 'package:lucky_care/features/health/health_controller.dart';
import 'package:lucky_care/features/health/health_models.dart';
import 'package:lucky_care/features/health/health_page.dart';
import 'package:lucky_care/features/health/medication_controller.dart';

import 'support/test_doubles.dart';

const WorkspaceContext _context = WorkspaceContext(
  workspaceId: 'ws-1',
  currentProfile: HealthProfileSummary(
    id: 'profile-1',
    personId: 'person-1',
    displayName: 'คุณพ่อ',
    relationship: 'self',
    kind: 'self',
  ),
);

final Uint8List _photoBytes = Uint8List.fromList(<int>[1, 2, 3, 4, 5, 6]);

const Map<String, dynamic> _medicationDraftJson = <String, dynamic>{
  'kind': 'medication_label',
  'requires_confirmation': true,
  'document_id': 'document-server-1',
  'confidence': 0.92,
  'model': 'vision-test-model',
  'warnings': <String>[],
  'fields': <String, dynamic>{
    'name': 'Amlodipine',
    'dosage': '5 mg',
    'doses_per_day': 2,
    'schedule_times': <String>['08:00', '20:00'],
    'instructions': 'ทานหลังอาหาร',
  },
};

const Map<String, dynamic> _deviceDraftJson = <String, dynamic>{
  'kind': 'device_reading',
  'requires_confirmation': true,
  'document_id': 'document-server-1',
  'confidence': 0.8,
  'model': 'vision-test-model',
  'warnings': <String>[],
  'fields': <String, dynamic>{
    'type': 'blood_pressure',
    'value': 120,
    'secondary_value': 80,
    'unit': 'mmHg',
    'measured_at': '2026-10-25T09:30:00+07:00',
  },
};

class _FakeDocumentsRepository extends DocumentsRepository {
  _FakeDocumentsRepository(this.events) : super(ApiClient());

  final List<String> events;
  int uploadCalls = 0;
  DocumentUploadException? uploadFailure;

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
    events.add('upload');
    onProgress?.call(100);

    final DocumentUploadException? failure = uploadFailure;

    if (failure != null) {
      throw failure;
    }

    return const MedicalDocument(
      id: 'document-server-1',
      kind: 'photo',
      mimeType: 'image/jpeg',
      sizeBytes: 6,
      status: 'available',
    );
  }
}

class _FakeAiRepository extends AiRepository {
  _FakeAiRepository(this.events) : super(ApiClient());

  final List<String> events;
  int extractCalls = 0;
  String? lastDocumentId;
  AiExtractionKind? lastKind;
  AiExtractionDraft? draft;
  Object? failure;

  @override
  Future<AiExtractionDraft> extract(
    WorkspaceContext context, {
    required String documentId,
    required AiExtractionKind kind,
    String? profileId,
  }) async {
    extractCalls++;
    events.add('extract');
    lastDocumentId = documentId;
    lastKind = kind;

    final Object? failure = this.failure;

    if (failure != null) {
      throw failure;
    }

    return draft ?? AiExtractionDraft.fromJson(_medicationDraftJson);
  }
}

class _FakePhotoSource implements PhotoSource {
  int pickCalls = 0;

  @override
  Future<PickedPhoto?> pick(PhotoOrigin origin) async {
    pickCalls++;

    return PickedPhoto(
      bytes: _photoBytes,
      filename: 'evidence-1712345678901234.jpg',
      mimeType: 'image/jpeg',
    );
  }
}

class _FixedWorkspaceController extends CurrentWorkspaceController {
  @override
  Future<WorkspaceContext> build() async => _context;
}

class _RecordingHealthController extends HealthController {
  int recordCalls = 0;
  CreateMeasurementInput? lastInput;

  @override
  Future<HealthData> build() async {
    return HealthData(
      hasProfile: true,
      measurements: const <HealthMeasurement>[],
    );
  }

  @override
  Future<void> record(CreateMeasurementInput input) async {
    recordCalls++;
    lastInput = input;
  }
}

class _RecordingMedicationController extends MedicationController {
  int createCalls = 0;
  CreateMedicationPlanInput? lastInput;

  @override
  Future<MedicationData> build() async {
    return MedicationData(
      hasProfile: true,
      plans: const <MedicationPlan>[],
      adherence: const <MedicationAdherence>[],
    );
  }

  @override
  Future<void> createPlan(CreateMedicationPlanInput input) async {
    createCalls++;
    lastInput = input;
  }
}

class _Controllers {
  _Controllers({
    _RecordingHealthController? health,
    _RecordingMedicationController? medication,
  }) : health = health ?? _RecordingHealthController(),
       medication = medication ?? _RecordingMedicationController();

  final _RecordingHealthController health;
  final _RecordingMedicationController medication;
}

Future<_Controllers> _pumpHealth(
  WidgetTester tester, {
  required _FakeDocumentsRepository documents,
  required _FakeAiRepository ai,
  required _FakePhotoSource photos,
  _Controllers? controllers,
  bool openMedication = false,
}) async {
  final _Controllers owned = controllers ?? _Controllers();

  await tester.pumpWidget(
    ProviderScope(
      overrides: <Override>[
        healthControllerProvider.overrideWith(() => owned.health),
        medicationControllerProvider.overrideWith(() => owned.medication),
        documentsRepositoryProvider.overrideWithValue(documents),
        aiRepositoryProvider.overrideWithValue(ai),
        photoSourceProvider.overrideWithValue(photos),
        currentWorkspaceProvider.overrideWith(_FixedWorkspaceController.new),
      ],
      child: MaterialApp(theme: buildAppTheme(), home: const HealthPage()),
    ),
  );
  await tester.pumpAndSettle();

  if (openMedication) {
    await tester.tap(find.byKey(const Key('health-section-medication')));
    await tester.pumpAndSettle();
  }

  return owned;
}

Future<void> _tap(WidgetTester tester, Key key) async {
  final Finder finder = find.byKey(key);

  await tester.ensureVisible(finder);
  await tester.pumpAndSettle();
  await tester.tap(finder);
  await tester.pumpAndSettle();
}

/// Small helper that owns the three fakes so each test reads as one flow.
class _ScanHarness {
  _ScanHarness._(this.events)
    : documents = _FakeDocumentsRepository(events),
      ai = _FakeAiRepository(events);

  final List<String> events;
  final _FakeDocumentsRepository documents;
  final _FakeAiRepository ai;
  final _FakePhotoSource photos = _FakePhotoSource();
  final _Controllers controllers = _Controllers();

  static Future<_ScanHarness> open(
    WidgetTester tester, {
    required AiExtractionKind kind,
  }) async {
    final _ScanHarness harness = _ScanHarness._(<String>[]);

    await _pumpHealth(
      tester,
      documents: harness.documents,
      ai: harness.ai,
      photos: harness.photos,
      controllers: harness.controllers,
      openMedication: kind == AiExtractionKind.medicationLabel,
    );

    await _tap(
      tester,
      kind == AiExtractionKind.medicationLabel
          ? const Key('medication-scan-label')
          : const Key('health-scan-device'),
    );

    return harness;
  }

  Future<void> pickCamera(WidgetTester tester) async {
    await _tap(tester, const Key('ocr-pick-camera'));
  }
}

void main() {
  group('OCR repository', () {
    test('posts the uploaded document id to ai/extractions', () async {
      final StubApiClient api = StubApiClient()
        ..postResponse = <String, dynamic>{'data': _medicationDraftJson};
      final AiRepository repository = AiRepository(api);

      final AiExtractionDraft draft = await repository.extract(
        _context,
        documentId: 'document-server-1',
        kind: AiExtractionKind.medicationLabel,
      );

      expect(
        api.requestedPaths.last,
        '/workspaces/ws-1/profiles/profile-1/ai/extractions',
      );
      expect(api.lastPostData, <String, dynamic>{
        'document_id': 'document-server-1',
        'kind': 'medication_label',
      });
      expect(draft.isMedication, isTrue);
      expect(draft.stringValue('name'), 'Amlodipine');
    });

    test('maps ai_vision_unavailable to its typed outcome', () async {
      final StubApiClient api = StubApiClient(
        failure: const ApiFailure(
          status: 503,
          code: 'ai_vision_unavailable',
          message: 'vision unavailable',
        ),
      );

      await expectLater(
        AiRepository(api).extract(
          _context,
          documentId: 'document-server-1',
          kind: AiExtractionKind.deviceReading,
        ),
        throwsA(isA<AiVisionUnavailable>()),
      );
    });

    test('a draft drops the document id and keys outside the schema', () {
      final AiExtractionDraft draft = AiExtractionDraft.fromJson(
        <String, dynamic>{
          'kind': 'device_reading',
          'requires_confirmation': true,
          'document_id': 'document-secret-991',
          'warnings': <String>['value_out_of_range'],
          'fields': <String, dynamic>{
            'type': 'blood_pressure',
            'value': 120,
            'raw_value': 187,
            'patient_name': 'สมชาย ใจดี',
          },
        },
      );

      expect(draft.fields.containsKey('raw_value'), isFalse);
      expect(draft.fields.containsKey('patient_name'), isFalse);
      expect(draft.fields.containsKey('document_id'), isFalse);
      expect(draft.warnings, <String>['value_out_of_range']);
    });

    test('createPlan posts to the normal medication endpoint', () async {
      final StubApiClient api = StubApiClient()
        ..postResponse = <String, dynamic>{
          'data': <String, dynamic>{
            'id': 'plan-new',
            'name': 'Amlodipine',
            'dosage': '5 mg',
            'form': 'tablet',
            'doses_per_day': 1,
            'schedule_times': <String>['08:00'],
            'starts_on': '2026-10-25',
            'status': 'active',
          },
        };

      await MedicationRepository(api).createPlan(
        _context,
        const CreateMedicationPlanInput(
          name: 'Amlodipine',
          dosage: '5 mg',
          dosesPerDay: 1,
          scheduleTimes: <String>['08:00'],
          startsOn: '2026-10-25',
        ),
      );

      expect(
        api.requestedPaths.last,
        '/workspaces/ws-1/profiles/profile-1/medication-plans',
      );
      expect(api.lastPostData, <String, dynamic>{
        'name': 'Amlodipine',
        'form': 'tablet',
        'doses_per_day': 1,
        'schedule_times': <String>['08:00'],
        'starts_on': '2026-10-25',
        'dosage': '5 mg',
      });
    });
  });

  group('OCR flow', () {
    testWidgets('OCR-T01 the ยา segment offers the scan action', (
      WidgetTester tester,
    ) async {
      final _ScanHarness harness = await _ScanHarness.open(
        tester,
        kind: AiExtractionKind.medicationLabel,
      );

      expect(find.byKey(const Key('medication-scan-label')), findsOneWidget);
      expect(find.byKey(const Key('ocr-photo-sheet')), findsOneWidget);
      expect(find.byKey(const Key('ocr-pick-camera')), findsOneWidget);
      expect(find.byKey(const Key('ocr-pick-gallery')), findsOneWidget);
      expect(harness.photos.pickCalls, 0);
    });

    testWidgets('OCR-T02 uploads first, then extracts with the document id', (
      WidgetTester tester,
    ) async {
      final _ScanHarness harness = await _ScanHarness.open(
        tester,
        kind: AiExtractionKind.medicationLabel,
      );

      harness.ai.draft = AiExtractionDraft.fromJson(_medicationDraftJson);

      await harness.pickCamera(tester);

      expect(harness.documents.uploadCalls, 1);
      expect(harness.ai.extractCalls, 1);
      expect(harness.ai.lastDocumentId, 'document-server-1');
      expect(harness.ai.lastKind, AiExtractionKind.medicationLabel);
      expect(harness.events, <String>['upload', 'extract']);
    });

    testWidgets('OCR-T03 the draft prefills every field and it is editable', (
      WidgetTester tester,
    ) async {
      final _ScanHarness harness = await _ScanHarness.open(
        tester,
        kind: AiExtractionKind.medicationLabel,
      );

      harness.ai.draft = AiExtractionDraft.fromJson(_medicationDraftJson);
      await harness.pickCamera(tester);

      expect(find.byKey(const Key('ocr-draft-sheet')), findsOneWidget);
      expect(find.text('ร่างจากภาพ - ตรวจสอบก่อนบันทึก'), findsOneWidget);

      final TextFormField nameField = tester.widget<TextFormField>(
        find.byKey(const Key('ocr-field-name')),
      );
      final TextFormField dosageField = tester.widget<TextFormField>(
        find.byKey(const Key('ocr-field-dosage')),
      );
      final TextFormField dosesField = tester.widget<TextFormField>(
        find.byKey(const Key('ocr-field-doses-per-day')),
      );
      final TextFormField scheduleField = tester.widget<TextFormField>(
        find.byKey(const Key('ocr-field-schedule-times')),
      );

      expect(nameField.controller!.text, 'Amlodipine');
      expect(dosageField.controller!.text, '5 mg');
      expect(dosesField.controller!.text, '2');
      expect(scheduleField.controller!.text, '08:00, 20:00');
      expect(nameField.enabled, isTrue);

      await tester.enterText(
        find.byKey(const Key('ocr-field-name')),
        'Amlodipine 10',
      );
      await tester.pump();

      expect(nameField.controller!.text, 'Amlodipine 10');
    });

    testWidgets('OCR-T04 warnings render and a dropped value stays empty', (
      WidgetTester tester,
    ) async {
      final _ScanHarness harness = await _ScanHarness.open(
        tester,
        kind: AiExtractionKind.medicationLabel,
      );

      harness.ai.draft = AiExtractionDraft.fromJson(<String, dynamic>{
        'kind': 'medication_label',
        'requires_confirmation': true,
        'document_id': 'document-server-1',
        'warnings': <String>['doses_per_day_out_of_range'],
        'fields': <String, dynamic>{
          'name': 'Amlodipine',
          'dosage': '5 mg',
        },
      });
      await harness.pickCamera(tester);

      expect(find.byKey(const Key('ocr-warnings')), findsOneWidget);
      expect(
        find.byKey(const Key('ocr-warning-doses_per_day_out_of_range')),
        findsOneWidget,
      );
      expect(
        tester
            .widget<Text>(
              find.byKey(
                const Key('ocr-warning-doses_per_day_out_of_range'),
              ),
            )
            .data,
        contains('จำนวนครั้งต่อวัน'),
      );

      final TextFormField dosesField = tester.widget<TextFormField>(
        find.byKey(const Key('ocr-field-doses-per-day')),
      );

      expect(dosesField.controller!.text, isEmpty);
    });

    testWidgets('OCR-T05 confirm saves the corrected values normally', (
      WidgetTester tester,
    ) async {
      final _ScanHarness harness = await _ScanHarness.open(
        tester,
        kind: AiExtractionKind.medicationLabel,
      );

      harness.ai.draft = AiExtractionDraft.fromJson(_medicationDraftJson);
      await harness.pickCamera(tester);

      await tester.enterText(
        find.byKey(const Key('ocr-field-name')),
        'Amlodipine 10',
      );
      await tester.enterText(
        find.byKey(const Key('ocr-field-dosage')),
        '10 mg',
      );
      await tester.pump();

      await _tap(tester, const Key('ocr-confirm'));

      expect(harness.controllers.medication.createCalls, 1);
      expect(harness.controllers.medication.lastInput!.name, 'Amlodipine 10');
      expect(harness.controllers.medication.lastInput!.dosage, '10 mg');
      expect(harness.controllers.medication.lastInput!.dosesPerDay, 2);
      expect(
        harness.controllers.medication.lastInput!.scheduleTimes,
        <String>['08:00', '20:00'],
      );

      // The AI was called once to read the photo, never to write.
      expect(harness.ai.extractCalls, 1);
      expect(harness.controllers.health.recordCalls, 0);
    });

    testWidgets('OCR-T05b a corrected measurement uses record()', (
      WidgetTester tester,
    ) async {
      final _ScanHarness harness = await _ScanHarness.open(
        tester,
        kind: AiExtractionKind.deviceReading,
      );

      harness.ai.draft = AiExtractionDraft.fromJson(_deviceDraftJson);
      await harness.pickCamera(tester);

      await tester.enterText(
        find.byKey(const Key('ocr-field-value')),
        '118',
      );
      await tester.pump();

      await _tap(tester, const Key('ocr-confirm'));

      expect(harness.controllers.health.recordCalls, 1);
      expect(harness.controllers.health.lastInput!.type, 'blood_pressure');
      expect(harness.controllers.health.lastInput!.value, 118);
      expect(harness.controllers.health.lastInput!.secondaryValue, 80);
      expect(harness.controllers.medication.createCalls, 0);
      expect(harness.ai.extractCalls, 1);
    });

    testWidgets('OCR-T06 ai_vision_unavailable shows the calm card only', (
      WidgetTester tester,
    ) async {
      final _ScanHarness harness = await _ScanHarness.open(
        tester,
        kind: AiExtractionKind.deviceReading,
      );

      harness.ai.failure = const AiVisionUnavailable();
      await harness.pickCamera(tester);

      expect(
        find.byKey(const Key('ai-vision-unavailable-card')),
        findsOneWidget,
      );
      expect(find.text('ยังอ่านภาพไม่ได้ในขณะนี้'), findsOneWidget);
      expect(find.byKey(const Key('ocr-draft-sheet')), findsNothing);
      expect(find.byKey(const Key('ocr-confirm')), findsNothing);
    });

    testWidgets('OCR-T06b ai_disabled reuses the shared card', (
      WidgetTester tester,
    ) async {
      final _ScanHarness harness = await _ScanHarness.open(
        tester,
        kind: AiExtractionKind.medicationLabel,
      );

      harness.ai.failure = const AiUnavailable();
      await harness.pickCamera(tester);

      expect(find.byKey(const Key('ai-unavailable-card')), findsOneWidget);
      expect(find.textContaining('ยังไม่เปิดใช้งาน'), findsWidgets);
      expect(find.byKey(const Key('ocr-draft-sheet')), findsNothing);
      expect(find.byKey(const Key('ocr-confirm')), findsNothing);
    });

    testWidgets('OCR-T07 an upload failure never calls the extraction', (
      WidgetTester tester,
    ) async {
      final _ScanHarness harness = await _ScanHarness.open(
        tester,
        kind: AiExtractionKind.medicationLabel,
      );

      harness.documents.uploadFailure = const DocumentUploadException(
        status: 503,
        code: 'storage_unavailable',
        message: 'อัปโหลดไม่สำเร็จ กรุณาลองใหม่',
      );

      await harness.pickCamera(tester);

      expect(harness.documents.uploadCalls, 1);
      expect(harness.ai.extractCalls, 0);
      expect(find.byKey(const Key('ocr-error')), findsOneWidget);
      expect(find.text('อัปโหลดไม่สำเร็จ กรุณาลองใหม่'), findsOneWidget);
      expect(find.byKey(const Key('ocr-draft-sheet')), findsNothing);
    });

    testWidgets('OCR-T08 no document id or raw fixture value is rendered', (
      WidgetTester tester,
    ) async {
      final _ScanHarness harness = await _ScanHarness.open(
        tester,
        kind: AiExtractionKind.deviceReading,
      );

      harness.ai.draft = AiExtractionDraft.fromJson(<String, dynamic>{
        'kind': 'device_reading',
        'requires_confirmation': true,
        'document_id': 'document-secret-991',
        'warnings': <String>[],
        'fields': <String, dynamic>{
          'type': 'blood_pressure',
          'value': 120,
          'secondary_value': 80,
          'unit': 'mmHg',
          'raw_value': 187,
          'patient_name': 'สมชาย ใจดี',
          'document_id': 'document-secret-991',
        },
      });
      await harness.pickCamera(tester);

      expect(find.byKey(const Key('ocr-draft-sheet')), findsOneWidget);
      expect(find.textContaining('document-secret-991'), findsNothing);
      expect(find.textContaining('สมชาย'), findsNothing);
      expect(find.textContaining('187'), findsNothing);

      final TextFormField valueField = tester.widget<TextFormField>(
        find.byKey(const Key('ocr-field-value')),
      );

      expect(valueField.controller!.text, '120');
    });
  });
}
