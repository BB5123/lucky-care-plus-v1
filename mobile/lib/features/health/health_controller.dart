import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/workspace/current_workspace.dart';
import 'health_models.dart';
import 'health_repository.dart';

/// Loads the current profile's readings, newest first.
class HealthController extends AsyncNotifier<HealthData> {
  @override
  Future<HealthData> build() async {
    final HealthRepository repository = ref.watch(healthRepositoryProvider);
    final WorkspaceContext context = await ref.watch(
      currentWorkspaceProvider.future,
    );

    if (!context.hasWorkspace || !context.hasProfile) {
      return HealthData.noProfile;
    }

    final List<HealthMeasurement> measurements = await repository.list(context);

    return HealthData(hasProfile: true, measurements: measurements);
  }

  void retry() {
    ref.invalidateSelf();
  }

  /// Saves a reading and prepends it to the current list, so the tab does not
  /// need a full refetch after a successful write.
  Future<void> record(CreateMeasurementInput input) async {
    final WorkspaceContext context = await ref.read(
      currentWorkspaceProvider.future,
    );
    final HealthRepository repository = ref.read(healthRepositoryProvider);
    final HealthMeasurement created = await repository.create(context, input);
    final HealthData? current = state.value;

    state = AsyncData<HealthData>(
      HealthData(
        hasProfile: true,
        measurements: <HealthMeasurement>[created, ...?current?.measurements],
      ),
    );
  }
}

final AsyncNotifierProvider<HealthController, HealthData>
healthControllerProvider = AsyncNotifierProvider<HealthController, HealthData>(
  HealthController.new,
  isAutoDispose: true,
  retry: (int retryCount, Object error) => null,
);
