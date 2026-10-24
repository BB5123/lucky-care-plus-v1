import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:lucky_care/core/network/api_failure.dart';
import 'package:lucky_care/core/workspace/current_workspace.dart';
import 'package:lucky_care/design_system/app_theme.dart';
import 'package:lucky_care/features/family/family_models.dart';
import 'package:lucky_care/features/family/family_page.dart';
import 'package:lucky_care/features/family/family_repository.dart';

const String _expiry = '2026-10-30T10:00:00Z';

const FamilyMembership _owner = FamilyMembership(
  id: 'membership-1',
  name: 'สมชาย ใจดี',
  role: 'profile_owner',
  status: 'active',
);

class _FixedWorkspaceController extends CurrentWorkspaceController {
  _FixedWorkspaceController(this.role);

  final String role;

  @override
  Future<WorkspaceContext> build() async {
    return WorkspaceContext(
      workspaceId: 'ws-1',
      workspaceName: 'บ้านใจดี',
      role: role,
    );
  }
}

/// In-memory [FamilyRepository]. It records every call so a test can prove the
/// form never reached the network, and it answers 422/403/404/409 with the
/// same [ApiFailure] the Dio layer would produce.
class _FakeFamilyRepository implements FamilyRepository {
  _FakeFamilyRepository({
    List<FamilyMembership> members = const <FamilyMembership>[],
    List<FamilyInvitation> invitations = const <FamilyInvitation>[],
  }) : _members = List<FamilyMembership>.of(members),
       _invitations = List<FamilyInvitation>.of(invitations);

  final List<FamilyMembership> _members;
  List<FamilyInvitation> _invitations;

  ApiFailure? inviteFailure;
  ApiFailure? revokeFailure;

  int inviteCalls = 0;
  String? lastEmail;
  String? lastRole;
  int revokeCalls = 0;
  String? lastRevokedId;

  @override
  Future<List<FamilyMembership>> list(WorkspaceContext context) async {
    return List<FamilyMembership>.of(_members);
  }

  @override
  Future<List<FamilyInvitation>> listInvitations(
    WorkspaceContext context,
  ) async {
    return List<FamilyInvitation>.of(_invitations);
  }

  @override
  Future<FamilyInvitation> invite(
    WorkspaceContext context, {
    required String email,
    required String role,
  }) async {
    inviteCalls++;
    lastEmail = email;
    lastRole = role;

    final ApiFailure? failure = inviteFailure;

    if (failure != null) {
      throw failure;
    }

    final FamilyInvitation created = FamilyInvitation(
      id: 'inv-$inviteCalls',
      email: email,
      role: role,
      expiresAt: _expiry,
      token: 'token-$inviteCalls',
    );

    _invitations = <FamilyInvitation>[created, ..._invitations];

    return created;
  }

  @override
  Future<void> revokeInvitation(
    WorkspaceContext context,
    String invitationId,
  ) async {
    revokeCalls++;
    lastRevokedId = invitationId;

    final ApiFailure? failure = revokeFailure;

    if (failure != null) {
      throw failure;
    }

    _invitations = _invitations
        .where(
          (FamilyInvitation invitation) => invitation.id != invitationId,
        )
        .toList(growable: false);
  }
}

Widget _harness(
  _FakeFamilyRepository repository, {
  String role = 'workspace_admin',
}) {
  return ProviderScope(
    overrides: [
      familyRepositoryProvider.overrideWithValue(repository),
      currentWorkspaceProvider.overrideWith(
        () => _FixedWorkspaceController(role),
      ),
    ],
    child: MaterialApp(theme: buildAppTheme(), home: const FamilyPage()),
  );
}

Future<void> _tap(WidgetTester tester, Key key) async {
  final Finder target = find.byKey(key);

  await tester.ensureVisible(target);
  await tester.tap(target);
  await tester.pumpAndSettle();
}

Future<void> _openSheet(WidgetTester tester) async {
  await _tap(tester, const Key('family-invite-action'));

  expect(find.byKey(const Key('family-invite-sheet')), findsOneWidget);
}

Future<void> _enterEmail(WidgetTester tester, String value) async {
  final Finder field = find.byKey(const Key('family-invite-email'));

  await tester.ensureVisible(field);
  await tester.enterText(field, value);
  await tester.pump();
}

Future<void> _chooseRole(WidgetTester tester, String role) async {
  final Finder chip = find.byKey(Key('family-invite-role-$role'));

  await tester.ensureVisible(chip);
  await tester.tap(chip);
  await tester.pump();
}

/// Captures the platform-channel calls so the copy test can inspect exactly
/// what was placed on the clipboard.
List<MethodCall> _capturePlatformCalls(WidgetTester tester) {
  final List<MethodCall> calls = <MethodCall>[];

  tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
    SystemChannels.platform,
    (MethodCall call) async {
      calls.add(call);

      return null;
    },
  );

  addTearDown(() {
    tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
      SystemChannels.platform,
      null,
    );
  });

  return calls;
}

Future<void> _invite(
  WidgetTester tester, {
  String email = 'guest@example.test',
  String role = 'caregiver',
}) async {
  await _openSheet(tester);
  await _enterEmail(tester, email);
  await _chooseRole(tester, role);
  await _tap(tester, const Key('family-invite-submit'));
}

void main() {
  testWidgets('M4-T01 the invite action is hidden when canInvite is false', (
    WidgetTester tester,
  ) async {
    final _FakeFamilyRepository repository = _FakeFamilyRepository();

    await tester.pumpWidget(_harness(repository, role: 'viewer'));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('family-page')), findsOneWidget);
    expect(find.byKey(const Key('family-invite-action')), findsNothing);
    expect(find.text('เชิญสมาชิก'), findsNothing);
  });

  testWidgets(
    'M4-T02 an invalid email is refused before the repository is called',
    (WidgetTester tester) async {
      final _FakeFamilyRepository repository = _FakeFamilyRepository(
        members: const <FamilyMembership>[_owner],
      );

      await tester.pumpWidget(_harness(repository));
      await tester.pumpAndSettle();

      // Positive control: the admin does see the action.
      expect(find.byKey(const Key('family-invite-action')), findsOneWidget);

      await _openSheet(tester);
      await _enterEmail(tester, 'somchai-at-example');

      await _tap(tester, const Key('family-invite-submit'));

      expect(find.text('กรุณากรอกอีเมลให้ถูกต้อง'), findsOneWidget);
      expect(repository.inviteCalls, 0);
      expect(find.byKey(const Key('family-invite-sheet')), findsOneWidget);
    },
  );

  testWidgets(
    'M4-T03 a valid invitation calls the repository with email and role, and '
    'the pending list shows it with an expiry',
    (WidgetTester tester) async {
      final _FakeFamilyRepository repository = _FakeFamilyRepository(
        members: const <FamilyMembership>[_owner],
      );

      await tester.pumpWidget(_harness(repository));
      await tester.pumpAndSettle();

      await _invite(tester);

      expect(repository.inviteCalls, 1);
      expect(repository.lastEmail, 'guest@example.test');
      expect(repository.lastRole, 'caregiver');

      expect(find.byKey(const Key('family-invite-success')), findsOneWidget);
      expect(find.byKey(const Key('family-invite-expiry')), findsOneWidget);
      expect(find.textContaining('ลิงก์หมดอายุ'), findsWidgets);

      await _tap(tester, const Key('family-invite-done'));

      expect(find.byKey(const Key('family-invite-sheet')), findsNothing);
      expect(
        find.byKey(const Key('family-invitation-inv-1')),
        findsOneWidget,
      );
      expect(
        find.byKey(const Key('family-invitation-expiry-inv-1')),
        findsOneWidget,
      );
      expect(find.textContaining('ลิงก์หมดอายุ'), findsOneWidget);
      expect(find.text('guest@example.test'), findsOneWidget);
      expect(find.text('ผู้ดูแล'), findsOneWidget);
    },
  );

  testWidgets('M4-T04 the copy action puts the invite link on the clipboard', (
    WidgetTester tester,
  ) async {
    final List<MethodCall> calls = _capturePlatformCalls(tester);
    final _FakeFamilyRepository repository = _FakeFamilyRepository(
      members: const <FamilyMembership>[_owner],
    );

    await tester.pumpWidget(_harness(repository));
    await tester.pumpAndSettle();

    await _invite(tester);
    await _tap(tester, const Key('family-invite-copy'));

    expect(find.byKey(const Key('family-invite-copied')), findsOneWidget);

    final List<MethodCall> writes = calls
        .where((MethodCall call) => call.method == 'Clipboard.setData')
        .toList(growable: false);

    expect(writes, isNotEmpty);

    final Map<Object?, Object?> arguments =
        writes.last.arguments as Map<Object?, Object?>;

    expect(arguments['text'], invitationLink('token-1'));
    expect(arguments['text'], contains('/invitations/token-1'));
  });

  testWidgets(
    'M4-T05 a 403 renders the calm Thai message and does not clear the form',
    (WidgetTester tester) async {
      final _FakeFamilyRepository repository = _FakeFamilyRepository(
        members: const <FamilyMembership>[_owner],
      )..inviteFailure = const ApiFailure(
        status: 403,
        code: 'forbidden',
        message: 'forbidden',
      );

      await tester.pumpWidget(_harness(repository));
      await tester.pumpAndSettle();

      await _invite(tester);

      expect(repository.inviteCalls, 1);
      expect(
        find.byKey(const Key('family-invite-forbidden')),
        findsOneWidget,
      );
      expect(find.text('คุณไม่มีสิทธิ์เชิญสมาชิก'), findsOneWidget);
      expect(find.byKey(const Key('family-invite-success')), findsNothing);

      final TextFormField emailField = tester.widget<TextFormField>(
        find.byKey(const Key('family-invite-email')),
      );

      expect(emailField.controller?.text, 'guest@example.test');

      final ChoiceChip roleChip = tester.widget<ChoiceChip>(
        find.byKey(const Key('family-invite-role-caregiver')),
      );

      expect(roleChip.selected, isTrue);
    },
  );

  testWidgets(
    'M4-T06 a 422 renders the field message next to the email field',
    (WidgetTester tester) async {
      final _FakeFamilyRepository repository = _FakeFamilyRepository(
        members: const <FamilyMembership>[_owner],
      )..inviteFailure = const ApiFailure(
        status: 422,
        code: 'validation',
        message: 'ข้อมูลไม่ถูกต้อง',
        fieldErrors: <String, String>{
          'email': 'รูปแบบอีเมลไม่ถูกต้องตามที่เซิร์ฟเวอร์กำหนด',
        },
      );

      await tester.pumpWidget(_harness(repository));
      await tester.pumpAndSettle();

      await _invite(tester);

      final Finder message = find.text(
        'รูปแบบอีเมลไม่ถูกต้องตามที่เซิร์ฟเวอร์กำหนด',
      );

      expect(repository.inviteCalls, 1);
      expect(message, findsOneWidget);
      expect(
        find.descendant(
          of: find.byKey(const Key('family-invite-email')),
          matching: message,
        ),
        findsOneWidget,
      );
      expect(find.byKey(const Key('family-invite-error')), findsNothing);
    },
  );

  testWidgets(
    'M4-T07 revoking asks for confirmation and removes the row only after '
    'success',
    (WidgetTester tester) async {
      final _FakeFamilyRepository repository = _FakeFamilyRepository(
        members: const <FamilyMembership>[_owner],
        invitations: const <FamilyInvitation>[
          FamilyInvitation(
            id: 'inv-9',
            email: 'pending@example.test',
            role: 'viewer',
            expiresAt: _expiry,
          ),
        ],
      );

      await tester.pumpWidget(_harness(repository));
      await tester.pumpAndSettle();

      final Finder row = find.byKey(const Key('family-invitation-inv-9'));

      expect(row, findsOneWidget);

      // Declining the dialog does nothing and keeps the row.
      await _tap(tester, const Key('family-invitation-revoke-inv-9'));

      expect(
        find.byKey(const Key('family-invitation-revoke-dialog')),
        findsOneWidget,
      );

      await _tap(tester, const Key('family-invitation-revoke-cancel'));

      expect(repository.revokeCalls, 0);
      expect(row, findsOneWidget);

      // A denied revoke keeps the row too.
      repository.revokeFailure = const ApiFailure(
        status: 403,
        code: 'forbidden',
        message: 'forbidden',
      );

      await _tap(tester, const Key('family-invitation-revoke-inv-9'));
      await _tap(tester, const Key('family-invitation-revoke-confirm'));

      expect(repository.revokeCalls, 1);
      expect(find.text('คุณไม่มีสิทธิ์ยกเลิกคำเชิญ'), findsOneWidget);
      expect(row, findsOneWidget);

      // Let the snack bar leave before the next interaction.
      await tester.pump(const Duration(seconds: 5));

      repository.revokeFailure = null;

      await _tap(tester, const Key('family-invitation-revoke-inv-9'));
      await _tap(tester, const Key('family-invitation-revoke-confirm'));

      expect(repository.revokeCalls, 2);
      expect(repository.lastRevokedId, 'inv-9');
      expect(row, findsNothing);
    },
  );
}
