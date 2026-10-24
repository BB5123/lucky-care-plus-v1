/// Runtime configuration.
///
/// Nothing here is secret: the API base is supplied at build time so a device
/// build never needs a hard-coded host. The Android emulator reaches the host
/// machine on `10.0.2.2`; a physical device passes
/// `--dart-define=API_BASE_URL=https://.../api/v1`.
abstract final class AppConfig {
  static const String apiBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'http://10.0.2.2:8000/api/v1',
  );

  /// Connect/receive timeout for every request (§2 of TASK D).
  static const Duration networkTimeout = Duration(seconds: 15);

  /// Sent as `device_name` so Sanctum can label/revoke the token per device.
  static const String deviceName = 'luckycare-mobile';
}
