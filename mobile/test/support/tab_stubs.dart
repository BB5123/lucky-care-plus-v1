import 'package:lucky_care/core/auth/auth_controller.dart';
import 'package:lucky_care/core/auth/auth_state.dart';
import 'package:lucky_care/core/medication/medication_models.dart';
import 'package:lucky_care/core/notifications/notification_models.dart';
import 'package:lucky_care/features/appointments/appointment_models.dart';
import 'package:lucky_care/features/appointments/appointments_controller.dart';
import 'package:lucky_care/features/dashboard/dashboard_controller.dart';
import 'package:lucky_care/features/dashboard/dashboard_models.dart';
import 'package:lucky_care/features/family/family_controller.dart';
import 'package:lucky_care/features/family/family_models.dart';
import 'package:lucky_care/features/health/health_controller.dart';
import 'package:lucky_care/features/health/health_models.dart';
import 'package:lucky_care/features/health/medication_controller.dart';
import 'package:lucky_care/features/notifications/notifications_controller.dart';
import 'package:lucky_care/features/tasks/task_models.dart';
import 'package:lucky_care/features/tasks/tasks_controller.dart';

import 'test_doubles.dart';

/// An already-authenticated session, so router tests never touch `/auth`.
class AuthenticatedAuthController extends AuthController {
  @override
  AuthState build() {
    return AuthState(
      status: AuthStatus.authenticated,
      user: testUser(),
      token: 'token-shell',
    );
  }
}

class StubDashboardController extends DashboardController {
  StubDashboardController(this.data);

  final DashboardData data;

  @override
  Future<DashboardData> build() async => data;
}

class StubHealthController extends HealthController {
  StubHealthController(this.data);

  final HealthData data;

  @override
  Future<HealthData> build() async => data;
}

class StubTasksController extends TasksController {
  StubTasksController(this.data);

  final TasksData data;

  @override
  Future<TasksData> build() async => data;
}

class StubAppointmentsController extends AppointmentsController {
  StubAppointmentsController(this.data);

  final AppointmentsData data;

  @override
  Future<AppointmentsData> build() async => data;
}

class StubFamilyController extends FamilyController {
  StubFamilyController(this.data);

  final FamilyData data;

  @override
  Future<FamilyData> build() async => data;
}

class StubMedicationController extends MedicationController {
  StubMedicationController(this.data);

  final MedicationData data;

  @override
  Future<MedicationData> build() async => data;
}

class StubNotificationDeliveriesController
    extends NotificationDeliveriesController {
  StubNotificationDeliveriesController(this.deliveries);

  final List<NotificationDelivery> deliveries;

  @override
  Future<List<NotificationDelivery>> build() async => deliveries;
}

class StubNotificationPreferencesController
    extends NotificationPreferencesController {
  StubNotificationPreferencesController(this.preferences);

  final NotificationPreferences preferences;

  @override
  Future<NotificationPreferences> build() async => preferences;
}
