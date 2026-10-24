import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/auth/auth_models.dart';
import '../../core/auth/auth_repository.dart';
import '../../core/network/api_client.dart';
import '../../core/network/json_read.dart';
import '../../core/network/network_providers.dart';
import 'dashboard_models.dart';

/// Reads the first screen: `/me` (which workspace?) then the aggregate
/// (`GET /workspaces/{w}/dashboard`).
///
/// The aggregate already applies per-card authorization, so the client never
/// filters data itself — it only respects `visible`.
class DashboardRepository {
  DashboardRepository(this._auth, this._api);

  final AuthRepository _auth;
  final ApiClient _api;

  Future<DashboardData> load() async {
    final MeResponse me = await _auth.me();

    if (me.workspaces.isEmpty) {
      return DashboardData.absent;
    }

    final MembershipSummary workspace = me.workspaces.first;
    final Map<String, dynamic> body = await _api.get(
      '/workspaces/${workspace.workspaceId}/dashboard',
    );

    return DashboardData(
      workspaceName: workspace.name,
      payload: DashboardPayload.fromJson(readMap(body['data'])),
    );
  }
}

final Provider<DashboardRepository> dashboardRepositoryProvider =
    Provider<DashboardRepository>(
      (Ref ref) => DashboardRepository(
        ref.watch(authRepositoryProvider),
        ref.watch(apiClientProvider),
      ),
    );
