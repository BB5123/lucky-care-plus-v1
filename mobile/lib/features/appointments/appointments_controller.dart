import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/workspace/current_workspace.dart';
import 'appointment_models.dart';
import 'appointments_repository.dart';

/// Loads the current profile's appointments, upcoming first.
class AppointmentsController extends AsyncNotifier<AppointmentsData> {
  @override
  Future<AppointmentsData> build() async {
    final AppointmentsRepository repository = ref.watch(
      appointmentsRepositoryProvider,
    );
    final WorkspaceContext context = await ref.watch(
      currentWorkspaceProvider.future,
    );

    if (!context.hasWorkspace || !context.hasProfile) {
      return AppointmentsData.noProfile;
    }

    return AppointmentsData(
      hasProfile: true,
      appointments: await repository.list(context),
    );
  }

  void retry() {
    ref.invalidateSelf();
  }
}

final AsyncNotifierProvider<AppointmentsController, AppointmentsData>
appointmentsControllerProvider =
    AsyncNotifierProvider<AppointmentsController, AppointmentsData>(
      AppointmentsController.new,
      isAutoDispose: true,
      retry: (int retryCount, Object error) => null,
    );
