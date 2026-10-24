import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:lucky_care/core/network/api_failure.dart';
import 'package:lucky_care/design_system/app_theme.dart';
import 'package:lucky_care/features/family/family_controller.dart';
import 'package:lucky_care/features/family/family_models.dart';
import 'package:lucky_care/features/family/family_page.dart';

class _ForbiddenFamilyController extends FamilyController {
  @override
  Future<FamilyData> build() async {
    throw const ApiFailure(
      status: 403,
      code: 'forbidden',
      message: 'คุณไม่มีสิทธิ์เข้าถึงข้อมูลนี้',
    );
  }
}

class _MemberFamilyController extends FamilyController {
  @override
  Future<FamilyData> build() async {
    return FamilyData(
      hasWorkspace: true,
      members: const <FamilyMembership>[
        FamilyMembership(
          id: 'membership-1',
          name: 'สมชาย ใจดี',
          role: 'profile_owner',
          status: 'active',
        ),
      ],
    );
  }
}

Widget _harness(FamilyController controller) {
  return ProviderScope(
    overrides: [familyControllerProvider.overrideWith(() => controller)],
    child: MaterialApp(theme: buildAppTheme(), home: const FamilyPage()),
  );
}

void main() {
  testWidgets(
    'D2-T08 a 403 renders the Matrix A explanation without a retry button',
    (WidgetTester tester) async {
      await tester.pumpWidget(_harness(_ForbiddenFamilyController()));
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('family-permission-state')), findsOneWidget);
      expect(find.text('ยังไม่สามารถดูรายชื่อสมาชิกได้'), findsOneWidget);
      expect(find.textContaining('ผู้ชมหรือผู้ดูแลรายวัน'), findsOneWidget);
      expect(find.text('ลองอีกครั้ง'), findsNothing);
      expect(find.byKey(const Key('family-error')), findsNothing);
    },
  );

  testWidgets('renders a member name, role label and status', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(_harness(_MemberFamilyController()));
    await tester.pumpAndSettle();

    expect(find.text('สมชาย ใจดี'), findsOneWidget);
    expect(find.text('เจ้าของโปรไฟล์'), findsOneWidget);
    expect(find.text('ใช้งานอยู่'), findsOneWidget);
  });
}
