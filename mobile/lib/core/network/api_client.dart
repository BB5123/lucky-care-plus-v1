import 'package:dio/dio.dart';

import '../config/app_config.dart';
import 'api_failure.dart';
import 'json_read.dart';

/// Thin wrapper around Dio.
///
/// Responsibilities:
///  - one base URL / timeout policy from [AppConfig];
///  - `Authorization: Bearer <token>` on every request when a token is present;
///  - every non-2xx and transport failure becomes an [ApiFailure] (never a raw
///    [DioException] leaking into the UI).
///
/// The token is held in memory by the auth controller; this class only mirrors
/// it. There is no disk persistence in this slice.
class ApiClient {
  ApiClient({Dio? dio, String baseUrl = AppConfig.apiBaseUrl})
    : _dio = dio ?? Dio() {
    _dio.options
      ..baseUrl = baseUrl
      ..connectTimeout = AppConfig.networkTimeout
      ..receiveTimeout = AppConfig.networkTimeout
      ..sendTimeout = AppConfig.networkTimeout
      ..responseType = ResponseType.json
      ..headers = <String, dynamic>{'Accept': 'application/json'};

    _dio.interceptors.add(InterceptorsWrapper(onRequest: _attachBearerToken));
  }

  final Dio _dio;
  String? _token;

  bool get hasToken => (_token ?? '').isNotEmpty;

  set token(String? value) {
    _token = value;
  }

  void _attachBearerToken(
    RequestOptions options,
    RequestInterceptorHandler handler,
  ) {
    final String? token = _token;

    if (token != null && token.isNotEmpty) {
      options.headers['Authorization'] = 'Bearer $token';
    }

    handler.next(options);
  }

  Future<Map<String, dynamic>> get(
    String path, {
    Map<String, dynamic>? query,
    Map<String, String>? headers,
  }) {
    return _send(
      () => _dio.get<dynamic>(
        path,
        queryParameters: query,
        options: _options(headers),
      ),
    );
  }

  Future<Map<String, dynamic>> post(
    String path, {
    Object? data,
    Map<String, String>? headers,
  }) {
    return _send(
      () => _dio.post<dynamic>(path, data: data, options: _options(headers)),
    );
  }

  /// For endpoints whose success body is empty (204, e.g. logout).
  Future<void> postVoid(
    String path, {
    Object? data,
    Map<String, String>? headers,
  }) async {
    await _send(
      () => _dio.post<dynamic>(path, data: data, options: _options(headers)),
    );
  }

  /// `null` when no per-request headers are needed, so Dio keeps its defaults.
  Options? _options(Map<String, String>? headers) {
    return headers == null ? null : Options(headers: headers);
  }

  Future<Map<String, dynamic>> _send(
    Future<Response<dynamic>> Function() call,
  ) async {
    try {
      final Response<dynamic> response = await call();

      return readMap(response.data);
    } on DioException catch (error) {
      throw ApiFailure.fromDio(error);
    }
  }
}
