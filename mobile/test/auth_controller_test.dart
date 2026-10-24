import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:lucky_care/core/auth/auth_controller.dart';
import 'package:lucky_care/core/auth/auth_repository.dart';
import 'package:lucky_care/core/auth/auth_state.dart';
import 'package:lucky_care/core/network/api_failure.dart';

import 'support/test_doubles.dart';

void main() {
  group('AuthController', () {
    test('D-T01 signIn sets authenticated + token on success', () async {
      final FakeAuthRepository repository = FakeAuthRepository(
        session: testSession(token: 'token-d-t01'),
      );
      final ProviderContainer container = ProviderContainer.test(
        overrides: [
          authRepositoryProvider.overrideWithValue(repository),
        ],
      );

      final bool signedIn = await container
          .read(authControllerProvider.notifier)
          .signIn(email: 'somchai@example.com', password: '1234567890');

      final AuthState state = container.read(authControllerProvider);

      expect(signedIn, isTrue);
      expect(state.status, AuthStatus.authenticated);
      expect(state.isAuthenticated, isTrue);
      expect(state.token, 'token-d-t01');
      expect(state.user?.email, 'somchai@example.com');
      expect(state.failure, isNull);
      expect(repository.loginCalls, 1);
    });

    test('D-T02 failed sign-in shows the API Thai message and stays logged out',
        () async {
      final FakeAuthRepository repository = FakeAuthRepository(
        loginFailure: const ApiFailure(
          status: 422,
          code: 'invalid_credentials',
          message: 'อีเมลหรือรหัสผ่านไม่ถูกต้อง',
        ),
      );
      final ProviderContainer container = ProviderContainer.test(
        overrides: [
          authRepositoryProvider.overrideWithValue(repository),
        ],
      );

      final bool signedIn = await container
          .read(authControllerProvider.notifier)
          .signIn(email: 'somchai@example.com', password: 'wrong-password');

      final AuthState state = container.read(authControllerProvider);

      expect(signedIn, isFalse);
      expect(state.isAuthenticated, isFalse);
      expect(state.token, isNull);
      expect(state.user, isNull);
      expect(state.failure?.message, 'อีเมลหรือรหัสผ่านไม่ถูกต้อง');
      expect(state.failure?.status, 422);
    });

    test('D-T03 signOut clears the token and the user', () async {
      final FakeAuthRepository repository = FakeAuthRepository(
        session: testSession(token: 'token-d-t03'),
      );
      final ProviderContainer container = ProviderContainer.test(
        overrides: [
          authRepositoryProvider.overrideWithValue(repository),
        ],
      );

      final AuthController controller = container.read(
        authControllerProvider.notifier,
      );

      await controller.signIn(
        email: 'somchai@example.com',
        password: '1234567890',
      );
      expect(container.read(authControllerProvider).isAuthenticated, isTrue);

      await controller.signOut();

      final AuthState state = container.read(authControllerProvider);

      expect(state.status, AuthStatus.unauthenticated);
      expect(state.token, isNull);
      expect(state.user, isNull);
      expect(state.isAuthenticated, isFalse);
      expect(repository.logoutCalls, 1);
    });
  });
}
