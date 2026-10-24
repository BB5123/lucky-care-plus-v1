import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:lucky_care/core/network/api_failure.dart';

void main() {
  group('ApiFailure', () {
    test('D-T04 maps errors{} into field errors', () {
      final ApiFailure failure = ApiFailure.fromResponse(
        422,
        <String, dynamic>{
          'code': 'validation',
          'detail': 'ข้อมูลไม่ถูกต้อง',
          'message': 'must not win over detail',
          'errors': <String, dynamic>{
            'email': <String>['อีเมลนี้ถูกใช้แล้ว', 'second sentence'],
            'password': 'รหัสผ่านไม่ปลอดภัย',
            'name': <String>[],
          },
        },
      );

      expect(failure.status, 422);
      expect(failure.code, 'validation');
      expect(failure.message, 'ข้อมูลไม่ถูกต้อง');
      expect(failure.fieldErrors['email'], 'อีเมลนี้ถูกใช้แล้ว');
      expect(failure.fieldErrors['password'], 'รหัสผ่านไม่ปลอดภัย');
      expect(failure.fieldErrors.containsKey('name'), isFalse);
    });

    test('a transport timeout becomes the network failure', () {
      final ApiFailure failure = ApiFailure.fromDio(
        DioException(
          requestOptions: RequestOptions(path: '/me'),
          type: DioExceptionType.connectionTimeout,
        ),
      );

      expect(failure.status, 0);
      expect(failure.code, 'network');
      expect(failure.message, ApiFailure.networkMessage);
      expect(failure.fieldErrors, isEmpty);
    });

    test('a body without detail falls back to a generic Thai sentence', () {
      final ApiFailure failure = ApiFailure.fromResponse(
        500,
        <String, dynamic>{'title': 'Server error'},
      );

      expect(failure.message, ApiFailure.genericMessage);
    });
  });
}
