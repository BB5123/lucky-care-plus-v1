import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../config/app_config.dart';
import '../network/api_client.dart';
import '../network/json_read.dart';
import '../network/network_providers.dart';
import 'auth_models.dart';

/// Identity endpoints. It never decides what the user may see; it only carries
/// the token and the user's own profile.
class AuthRepository {
  AuthRepository(this._api);

  final ApiClient _api;

  Future<AuthSession> login({
    required String email,
    required String password,
  }) async {
    final Map<String, dynamic> body = await _api.post(
      '/auth/login',
      data: <String, dynamic>{
        'email': email,
        'password': password,
        'device_name': AppConfig.deviceName,
      },
    );

    return AuthSession.fromJson(readMap(body['data']));
  }

  Future<AuthSession> register({
    required String name,
    required String email,
    required String password,
    required String confirmation,
  }) async {
    final Map<String, dynamic> body = await _api.post(
      '/auth/register',
      data: <String, dynamic>{
        'name': name,
        'email': email,
        'password': password,
        'password_confirmation': confirmation,
        'device_name': AppConfig.deviceName,
      },
    );

    return AuthSession.fromJson(readMap(body['data']));
  }

  Future<MeResponse> me() async {
    final Map<String, dynamic> body = await _api.get('/me');

    return MeResponse.fromJson(readMap(body['data']));
  }

  /// Revokes the current bearer token server-side (best effort).
  Future<void> logout() => _api.postVoid('/auth/logout');
}

final Provider<AuthRepository> authRepositoryProvider =
    Provider<AuthRepository>(
      (Ref ref) => AuthRepository(ref.watch(apiClientProvider)),
    );
