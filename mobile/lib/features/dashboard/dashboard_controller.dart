import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'dashboard_models.dart';
import 'dashboard_repository.dart';

/// Loads the dashboard once; [retry] rebuilds it after a recoverable failure.
class DashboardController extends AsyncNotifier<DashboardData> {
  @override
  Future<DashboardData> build() {
    return ref.watch(dashboardRepositoryProvider).load();
  }

  void retry() {
    ref.invalidateSelf();
  }
}

final AsyncNotifierProvider<DashboardController, DashboardData>
dashboardControllerProvider =
    AsyncNotifierProvider<DashboardController, DashboardData>(
      DashboardController.new,
      // Dispose when the dashboard leaves the tree (sign-out), so a different
      // account can never see the previous account's cached payload.
      isAutoDispose: true,
      // A 403/404 is a decision, not a transient fault: retrying it would only
      // repeat the same denial and make the UI flicker.
      retry: (int retryCount, Object error) => null,
    );
