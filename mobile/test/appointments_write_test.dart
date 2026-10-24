import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:lucky_care/core/network/api_failure.dart';
import 'package:lucky_care/core/profiles/profile.models.dart';
import 'package:lucky_care/core/workspace/current_workspace.dart';
import 'package:lucky_care/design_system/app_theme.dart';
import 'package:lucky_care/features/appointments/appointment_models.dart';
import 'package:lucky_care/features/appointments/appointments_page.dart';
import 'package:lucky_care/features/appointments/appointments_repository.dart';
import 'package:lucky_care/features/appointments/widgets/appointment_form_sheet.dart';

const HealthProfileSummary _profile = HealthProfileSummary(
  id: 'profile-1',
  personId: 'person-1',
  displayName: 'สมชาย ใจดี',
  relationship: 'self',
  kind: 'self',
);

const WorkspaceContext _workspace = WorkspaceContext(
  workspaceId: 'ws-1',
  workspaceName: 'บ้านใจดี',
  role: 'profile_owner',
  profiles: <HealthProfileSummary>[_profile],
  currentProfile: _profile,
);

class _FixedWorkspaceController extends CurrentWorkspaceController {
  @override
  Future<WorkspaceContext> build() async => _workspace;
}

/// Records every write without touching Dio. The list is a simple in-memory
/// table so the page can show a created/updated row exactly like the server.
class _FakeAppointmentsRepository implements AppointmentsRepository {
  _FakeAppointmentsRepository({
    List<Appointment> appointments = const <Appointment>[],
    this.createFailure,
  }) : _appointments = List<Appointment>.of(appointments);

  List<Appointment> _appointments;

  /// When set, the next [list] returns this table (simulating another device
  /// having written the row before the refetch).
  List<Appointment>? staleReload;

  ApiFailure? createFailure;
  ApiFailure? updateFailure;
  ApiFailure? cancelFailure;

  int listCalls = 0;
  int createCalls = 0;
  int updateCalls = 0;
  int cancelCalls = 0;

  String? lastCreateProfileId;
  String? lastCreateTitle;
  DateTime? lastCreateStartsAt;
  int? lastCreateDuration;
  String? lastCreateLocation;
  String? lastCreateClinician;
  String? lastCreateNotes;

  String? lastUpdateAppointmentId;
  String? lastIfMatch;
  Map<String, dynamic>? lastUpdateData;

  String? lastCancelAppointmentId;
  String? lastCancelIfMatch;

  @override
  Future<List<Appointment>> list(WorkspaceContext context) async {
    listCalls++;

    return List<Appointment>.of(staleReload ?? _appointments);
  }

  @override
  Future<Appointment> create(
    WorkspaceContext context, {
    String? profileId,
    required String title,
    required DateTime startsAt,
    int? durationMinutes,
    String? location,
    String? clinician,
    String? notes,
  }) async {
    createCalls++;
    lastCreateProfileId = profileId;
    lastCreateTitle = title;
    lastCreateStartsAt = startsAt;
    lastCreateDuration = durationMinutes;
    lastCreateLocation = location;
    lastCreateClinician = clinician;
    lastCreateNotes = notes;

    final ApiFailure? failure = createFailure;

    if (failure != null) {
      throw failure;
    }

    final Appointment created = Appointment(
      id: 'created-$createCalls',
      title: title,
      status: 'planned',
      providerName: clinician,
      location: location,
      notes: notes,
      scheduledAt: startsAt.toUtc().toIso8601String(),
      durationMinutes: durationMinutes,
      version: 1,
    );

    _appointments = <Appointment>[..._appointments, created];

    return created;
  }

  @override
  Future<Appointment> update(
    WorkspaceContext context,
    String appointmentId, {
    required int version,
    String? title,
    DateTime? startsAt,
    int? durationMinutes,
    String? location,
    String? clinician,
    String? notes,
  }) async {
    updateCalls++;
    lastUpdateAppointmentId = appointmentId;
    lastIfMatch = version.toString();
    lastUpdateData = <String, dynamic>{};

    if (title != null) {
      lastUpdateData!['title'] = title;
    }

    if (startsAt != null) {
      lastUpdateData!['starts_at'] = startsAt.toUtc().toIso8601String();
    }

    if (durationMinutes != null) {
      lastUpdateData!['duration_minutes'] = durationMinutes;
    }

    if (location != null) {
      lastUpdateData!['location'] = location;
    }

    if (clinician != null) {
      lastUpdateData!['provider_name'] = clinician;
    }

    if (notes != null) {
      lastUpdateData!['purpose'] = notes;
    }

    final ApiFailure? failure = updateFailure;

    if (failure != null) {
      final List<Appointment>? fresh = staleReload;

      if (fresh != null) {
        _appointments = List<Appointment>.of(fresh);
      }

      throw failure;
    }

    final Appointment original = _appointments.firstWhere(
      (Appointment appointment) => appointment.id == appointmentId,
    );
    final Appointment updated = Appointment(
      id: original.id,
      title: title ?? original.title,
      status: original.status,
      providerName: clinician ?? original.providerName,
      facility: original.facility,
      location: location ?? original.location,
      notes: notes ?? original.notes,
      scheduledAt: startsAt == null
          ? original.scheduledAt
          : startsAt.toUtc().toIso8601String(),
      durationMinutes: durationMinutes ?? original.durationMinutes,
      version: version + 1,
    );

    _replace(updated);

    return updated;
  }

  @override
  Future<Appointment> cancel(
    WorkspaceContext context,
    String appointmentId,
    int version,
  ) async {
    cancelCalls++;
    lastCancelAppointmentId = appointmentId;
    lastCancelIfMatch = version.toString();

    final ApiFailure? failure = cancelFailure;

    if (failure != null) {
      throw failure;
    }

    final Appointment original = _appointments.firstWhere(
      (Appointment appointment) => appointment.id == appointmentId,
    );
    final Appointment cancelled = Appointment(
      id: original.id,
      title: original.title,
      status: 'cancelled',
      providerName: original.providerName,
      facility: original.facility,
      location: original.location,
      notes: original.notes,
      scheduledAt: original.scheduledAt,
      durationMinutes: original.durationMinutes,
      version: version + 1,
    );

    _replace(cancelled);

    return cancelled;
  }

  void _replace(Appointment updated) {
    final List<Appointment> next = List<Appointment>.of(_appointments);
    final int index = next.indexWhere(
      (Appointment appointment) => appointment.id == updated.id,
    );

    if (index >= 0) {
      next[index] = updated;
    } else {
      next.add(updated);
    }

    _appointments = next;
  }
}

Widget _pageHarness(_FakeAppointmentsRepository repository) {
  return ProviderScope(
    overrides: [
      appointmentsRepositoryProvider.overrideWithValue(repository),
      currentWorkspaceProvider.overrideWith(_FixedWorkspaceController.new),
    ],
    child: MaterialApp(theme: buildAppTheme(), home: const AppointmentsPage()),
  );
}

Widget _sheetHarness(
  _FakeAppointmentsRepository repository, {
  DateTime? initialStartsAt,
}) {
  return ProviderScope(
    overrides: [
      appointmentsRepositoryProvider.overrideWithValue(repository),
      currentWorkspaceProvider.overrideWith(_FixedWorkspaceController.new),
    ],
    child: MaterialApp(
      theme: buildAppTheme(),
      home: Scaffold(
        body: AppointmentFormSheet(initialStartsAt: initialStartsAt),
      ),
    ),
  );
}

Appointment _appointment({
  String id = 'appointment-1',
  String title = 'ตรวจติดตามอาการ',
  String status = 'planned',
  DateTime? scheduledAt,
  int durationMinutes = 30,
  String? location = 'โรงพยาบาลตัวอย่าง',
  String? clinician = 'นพ. ใจดี',
  String? notes = 'งดน้ำและอาหารก่อนตรวจ',
  int version = 1,
}) {
  return Appointment(
    id: id,
    title: title,
    status: status,
    scheduledAt: (scheduledAt ?? DateTime.now().add(const Duration(days: 2)))
        .toIso8601String(),
    durationMinutes: durationMinutes,
    location: location,
    providerName: clinician,
    notes: notes,
    version: version,
  );
}

Future<void> _tapSave(WidgetTester tester) async {
  final Finder save = find.byKey(const Key('appointment-save'));

  await tester.ensureVisible(save);
  await tester.tap(save);
  await tester.pumpAndSettle();
}

Future<void> _enter(WidgetTester tester, Key key, String value) async {
  final Finder field = find.byKey(key);

  await tester.ensureVisible(field);
  await tester.enterText(field, value);
  await tester.pump();
}

void main() {
  testWidgets(
    'M3-T01 the create action opens the sheet and an empty title is refused '
    'before the repository is called',
    (WidgetTester tester) async {
      final _FakeAppointmentsRepository repository =
          _FakeAppointmentsRepository();

      await tester.pumpWidget(_pageHarness(repository));
      await tester.pumpAndSettle();

      expect(
        find.byKey(const Key('appointment-create-action')),
        findsOneWidget,
      );

      await tester.tap(find.byKey(const Key('appointment-create-action')));
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('appointment-form-sheet')), findsOneWidget);
      expect(
        find.descendant(
          of: find.byKey(const Key('appointment-form-sheet')),
          matching: find.text('นัดหมายใหม่'),
        ),
        findsOneWidget,
      );

      await _tapSave(tester);

      expect(find.text('กรุณากรอกชื่อนัดหมาย'), findsOneWidget);
      expect(repository.createCalls, 0);
      expect(find.byKey(const Key('appointment-form-sheet')), findsOneWidget);
    },
  );

  testWidgets(
    'M3-T02 a start time in the past is refused with a Thai message',
    (WidgetTester tester) async {
      final _FakeAppointmentsRepository repository =
          _FakeAppointmentsRepository();

      await tester.pumpWidget(
        _sheetHarness(
          repository,
          initialStartsAt: DateTime.now().subtract(const Duration(hours: 1)),
        ),
      );
      await tester.pumpAndSettle();

      await _enter(tester, const Key('appointment-title'), 'นัดที่ผ่านมาแล้ว');
      await _tapSave(tester);

      expect(find.text('เวลาเริ่มต้องเป็นเวลาในอนาคต'), findsOneWidget);
      expect(repository.createCalls, 0);
    },
  );

  testWidgets(
    'M3-T03 a valid submission calls the repository with the right fields and '
    'the list shows the new item',
    (WidgetTester tester) async {
      final _FakeAppointmentsRepository repository =
          _FakeAppointmentsRepository();

      await tester.pumpWidget(_pageHarness(repository));
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const Key('appointment-create-action')));
      await tester.pumpAndSettle();

      await _enter(tester, const Key('appointment-title'), 'นัดตรวจหัวใจ');
      await _enter(tester, const Key('appointment-duration'), '45');
      await _enter(tester, const Key('appointment-location'), 'โรงพยาบาลใจดี');
      await _enter(tester, const Key('appointment-clinician'), 'พญ. สุขใจ');
      await _enter(
        tester,
        const Key('appointment-notes'),
        'นำผลเลือดเดิมมาด้วย',
      );

      await _tapSave(tester);

      expect(repository.createCalls, 1);
      expect(repository.lastCreateProfileId, 'profile-1');
      expect(repository.lastCreateTitle, 'นัดตรวจหัวใจ');
      expect(repository.lastCreateDuration, 45);
      expect(repository.lastCreateLocation, 'โรงพยาบาลใจดี');
      expect(repository.lastCreateClinician, 'พญ. สุขใจ');
      expect(repository.lastCreateNotes, 'นำผลเลือดเดิมมาด้วย');
      expect(repository.lastCreateStartsAt!.isAfter(DateTime.now()), isTrue);

      expect(find.byKey(const Key('appointment-form-sheet')), findsNothing);
      expect(find.text('นัดตรวจหัวใจ'), findsOneWidget);
      expect(find.byKey(const Key('appointment-created-1')), findsOneWidget);
    },
  );

  testWidgets(
    'M3-T04 editing sends only the changed fields and the current version in '
    'If-Match',
    (WidgetTester tester) async {
      final _FakeAppointmentsRepository repository =
          _FakeAppointmentsRepository(
            appointments: <Appointment>[
              _appointment(id: 'edit-1', title: 'นัดเดิม', version: 7),
            ],
          );

      await tester.pumpWidget(_pageHarness(repository));
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const Key('appointment-edit-1')));
      await tester.pumpAndSettle();

      expect(find.text('แก้ไขนัดหมาย'), findsOneWidget);

      await _enter(tester, const Key('appointment-title'), 'นัดที่แก้ชื่อ');
      await _tapSave(tester);

      expect(repository.updateCalls, 1);
      expect(repository.lastUpdateAppointmentId, 'edit-1');
      expect(repository.lastIfMatch, '7');
      expect(repository.lastUpdateData, <String, dynamic>{
        'title': 'นัดที่แก้ชื่อ',
      });

      expect(find.byKey(const Key('appointment-form-sheet')), findsNothing);
      expect(find.text('นัดที่แก้ชื่อ'), findsOneWidget);
    },
  );

  testWidgets(
    'M3-T05 a 412 renders the stale message with a reload action that refetches',
    (WidgetTester tester) async {
      final _FakeAppointmentsRepository repository =
          _FakeAppointmentsRepository(
              appointments: <Appointment>[
                _appointment(id: 'stale-1', title: 'นัดเดิม', version: 2),
              ],
            )
            ..updateFailure = const ApiFailure(
              status: 412,
              code: 'version_conflict',
              message: 'ข้อมูลถูกแก้ไขโดยผู้อื่น กรุณาโหลดข้อมูลใหม่',
            )
            ..staleReload = <Appointment>[
              _appointment(
                id: 'stale-1',
                title: 'นัดที่คนอื่นแก้แล้ว',
                version: 3,
              ),
            ];

      await tester.pumpWidget(_pageHarness(repository));
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const Key('appointment-stale-1')));
      await tester.pumpAndSettle();

      await _enter(tester, const Key('appointment-title'), 'นัดที่ฉันแก้');
      await _tapSave(tester);

      expect(find.text('นัดหมายถูกแก้ไขจากอุปกรณ์อื่น'), findsOneWidget);
      expect(find.byKey(const Key('appointment-reload')), findsOneWidget);
      expect(repository.listCalls, 1);

      await tester.tap(find.byKey(const Key('appointment-reload')));
      await tester.pumpAndSettle();

      expect(repository.listCalls, 2);
      expect(find.byKey(const Key('appointment-form-sheet')), findsOneWidget);

      final TextFormField titleField = tester.widget<TextFormField>(
        find.byKey(const Key('appointment-title')),
      );

      expect(titleField.controller?.text, 'นัดที่คนอื่นแก้แล้ว');
      expect(find.text('นัดหมายถูกแก้ไขจากอุปกรณ์อื่น'), findsNothing);
    },
  );

  testWidgets(
    'M3-T06 a 422 renders the field error next to the field, not as a banner',
    (WidgetTester tester) async {
      final _FakeAppointmentsRepository repository =
          _FakeAppointmentsRepository(
            createFailure: const ApiFailure(
              status: 422,
              code: 'validation',
              message: 'ข้อมูลไม่ถูกต้อง',
              fieldErrors: <String, String>{
                'title': 'ชื่อนัดหมายต้องไม่เกิน 160 ตัวอักษร',
              },
            ),
          );

      await tester.pumpWidget(_pageHarness(repository));
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const Key('appointment-create-action')));
      await tester.pumpAndSettle();

      await _enter(tester, const Key('appointment-title'), 'นัดยาวเกินขนาด');
      await _tapSave(tester);

      final Finder message = find.text('ชื่อนัดหมายต้องไม่เกิน 160 ตัวอักษร');

      expect(repository.createCalls, 1);
      expect(message, findsOneWidget);
      expect(
        find.descendant(
          of: find.byKey(const Key('appointment-title')),
          matching: message,
        ),
        findsOneWidget,
      );
      expect(find.byKey(const Key('appointment-form-error')), findsNothing);
    },
  );

  testWidgets(
    'M3-T07 cancel asks for confirmation and does nothing when declined',
    (WidgetTester tester) async {
      final _FakeAppointmentsRepository repository =
          _FakeAppointmentsRepository(
            appointments: <Appointment>[
              _appointment(id: 'cancel-1', title: 'นัดที่จะยกเลิก'),
            ],
          );

      await tester.pumpWidget(_pageHarness(repository));
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const Key('appointment-cancel-1')));
      await tester.pumpAndSettle();

      await tester.ensureVisible(
        find.byKey(const Key('appointment-cancel-action')),
      );
      await tester.tap(find.byKey(const Key('appointment-cancel-action')));
      await tester.pumpAndSettle();

      expect(
        find.byKey(const Key('appointment-cancel-dialog')),
        findsOneWidget,
      );
      expect(
        find.descendant(
          of: find.byKey(const Key('appointment-cancel-dialog')),
          matching: find.textContaining('นัดที่จะยกเลิก'),
        ),
        findsOneWidget,
      );

      await tester.tap(find.byKey(const Key('appointment-cancel-dismiss')));
      await tester.pumpAndSettle();

      expect(repository.cancelCalls, 0);
      expect(find.byKey(const Key('appointment-cancel-dialog')), findsNothing);
      expect(find.byKey(const Key('appointment-form-sheet')), findsOneWidget);
    },
  );
}
