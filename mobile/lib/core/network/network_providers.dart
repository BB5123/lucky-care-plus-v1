import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'api_client.dart';

/// One Dio-backed client for the whole app. The auth controller writes the
/// bearer token into it; every repository shares the same instance.
final Provider<ApiClient> apiClientProvider = Provider<ApiClient>(
  (Ref ref) => ApiClient(),
);
