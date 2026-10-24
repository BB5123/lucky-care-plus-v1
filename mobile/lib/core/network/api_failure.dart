import 'package:dio/dio.dart';

import 'json_read.dart';

/// A failure that is safe to render.
///
/// `message` is either the server-authored Thai sentence from the RFC 9457
/// body (`detail`, then `message`) or a generic Thai sentence. Raw exception
/// text, stack traces and response dumps never leave this class (N-02).
class ApiFailure implements Exception {
  const ApiFailure({
    required this.status,
    required this.code,
    required this.message,
    this.fieldErrors = const <String, String>{},
  });

  /// HTTP status, or `0` when the request never reached the server.
  final int status;

  /// Machine code from the problem body (`unauthenticated`, `forbidden`, ...),
  /// or `network` / `protocol` for transport and envelope failures.
  final String code;

  /// Thai sentence for the user.
  final String message;

  /// `errors{field: [sentence]}` from a 422, reduced to the first safe sentence.
  final Map<String, String> fieldErrors;

  static const String networkMessage =
      'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ กรุณาลองใหม่';
  static const String genericMessage =
      'เกิดข้อผิดพลาดบางอย่าง กรุณาลองใหม่อีกครั้ง';

  /// Fired when the transport failed and there is no HTTP response.
  static const ApiFailure network = ApiFailure(
    status: 0,
    code: 'network',
    message: networkMessage,
  );

  /// Fired when a 2xx body does not match the documented envelope.
  static const ApiFailure protocol = ApiFailure(
    status: 0,
    code: 'protocol',
    message: genericMessage,
  );

  static const ApiFailure generic = ApiFailure(
    status: 0,
    code: 'unknown',
    message: genericMessage,
  );

  /// 403 and 404 are rendered as an empty state, not as an error with a retry:
  /// the server deliberately does not reveal whether the resource exists.
  bool get isPermissionDenied => status == 403 || status == 404;

  bool get isUnauthenticated => status == 401;

  factory ApiFailure.fromDio(DioException error) {
    final Response<dynamic>? response = error.response;

    if (response == null) {
      return network;
    }

    return ApiFailure.fromResponse(
      response.statusCode ?? 0,
      response.data,
    );
  }

  factory ApiFailure.fromResponse(int status, Object? body) {
    final Map<String, dynamic> payload = readMap(body);
    final String? detail = _safeText(payload['detail']);
    final String? message = _safeText(payload['message']);
    final String? code = _safeText(payload['code']);

    return ApiFailure(
      status: status,
      code: code ?? _fallbackCode(status),
      message: detail ?? message ?? genericMessage,
      fieldErrors: _readFieldErrors(payload['errors']),
    );
  }

  /// Prefers `detail`, then `message`; never a stack trace or framework path.
  static final RegExp _internalDetail = RegExp(
    r'(\bat\s+\w+\s+\(|stack trace|traceback|/vendor/|\.php:\d+)',
    caseSensitive: false,
  );

  static String? _safeText(Object? value) {
    if (value is! String) {
      return null;
    }

    final String trimmed = value.trim();

    if (trimmed.isEmpty || _internalDetail.hasMatch(trimmed)) {
      return null;
    }

    return trimmed;
  }

  static Map<String, String> _readFieldErrors(Object? raw) {
    if (raw is! Map) {
      return const <String, String>{};
    }

    final Map<String, String> fieldErrors = <String, String>{};

    for (final MapEntry<Object?, Object?> entry in raw.entries) {
      final Object? key = entry.key;

      if (key is! String) {
        continue;
      }

      final Object? value = entry.value;
      String? candidate;

      if (value is List) {
        for (final Object? item in value) {
          if (item is String) {
            candidate = item;
            break;
          }
        }
      } else if (value is String) {
        candidate = value;
      }

      final String? safe = _safeText(candidate);

      if (safe != null) {
        fieldErrors[key] = safe;
      }
    }

    return Map<String, String>.unmodifiable(fieldErrors);
  }

  static String _fallbackCode(int status) {
    return switch (status) {
      401 => 'unauthenticated',
      403 => 'forbidden',
      404 => 'out_of_scope',
      422 => 'validation',
      429 => 'rate_limited',
      _ => 'unknown',
    };
  }

  @override
  String toString() => 'ApiFailure(status: $status, code: $code)';
}
