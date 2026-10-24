import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:lucky_care/design_system/app_theme.dart';
import 'package:lucky_care/features/appointments/appointment_models.dart';
import 'package:lucky_care/features/appointments/appointments_controller.dart';
import 'package:lucky_care/features/appointments/appointments_page.dart';

import 'support/tab_stubs.dart';

void main() {
  testWidgets(
    'D2 appointments are read-only, upcoming first, with a Thai date and status',
    (WidgetTester tester) async {
      final DateTime now = DateTime.now();
      final Appointment upcoming = Appointment(
        id: 'upcoming',
        title: 'ตรวจติดตามอาการ',
        status: 'confirmed',
        facility: 'โรงพยาบาลตัวอย่าง',
        scheduledAt: now.add(const Duration(days: 2)).toIso8601String(),
      );
      final Appointment past = Appointment(
        id: 'past',
        title: 'นัดครั้งก่อน',
        status: 'attended',
        scheduledAt: now.subtract(const Duration(days: 2)).toIso8601String(),
      );

      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            appointmentsControllerProvider.overrideWith(
              () => StubAppointmentsController(
                AppointmentsData(
                  hasProfile: true,
                  appointments: <Appointment>[past, upcoming],
                  now: now,
                ),
              ),
            ),
          ],
          child: MaterialApp(
            theme: buildAppTheme(),
            home: const AppointmentsPage(),
          ),
        ),
      );
      await tester.pumpAndSettle();

      expect(
        find.byKey(const Key('appointments-read-only-note')),
        findsOneWidget,
      );
      expect(
        find.text('การสร้างหรือแก้ไขนัดหมายจะมาในรุ่นถัดไป'),
        findsOneWidget,
      );
      expect(find.text('ตรวจติดตามอาการ'), findsOneWidget);
      expect(find.text('ยืนยันแล้ว'), findsOneWidget);
      expect(find.text('เข้ารับบริการแล้ว'), findsOneWidget);

      final double upcomingTop = tester
          .getTopLeft(find.byKey(const Key('appointment-upcoming')))
          .dy;
      final double pastTop = tester
          .getTopLeft(find.byKey(const Key('appointment-past')))
          .dy;

      expect(upcomingTop, lessThan(pastTop));
    },
  );
}
