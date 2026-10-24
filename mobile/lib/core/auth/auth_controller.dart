import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../network/api_failure.dart';
import '../network/network_providers.dart';
import 'auth_models.dart';
import 'auth_repository.dart';
import 'auth_state.dart';

/// Global session state. The token lives in memory only in this slice; signing
/// out clears both the token and the user, and tells the router to leave the
/// dashboard.
class AuthController extends Notifier<AuthState> {
  @override
  AuthState build() => const AuthState();

  AuthRepository get _repository => ref.read(authRepositoryProvider);

  Future<bool> signIn({
    required String email,
    required String password,
  }) async {
    state = AuthState(
      status: AuthStatus.authenticating,
      user: state.user,
      token: state.token,
    );

    try {
      final AuthSession session = await _repository.login(
        email: email,
        password: password,
      );
      _applySession(session);

      return true;
    } on ApiFailure catch (failure) {
      state = AuthState(failure: failure);

      return false;
    } on Object {
      state = const AuthState(failure: ApiFailure.generic);

      return false;
    }
  }

  Future<bool> signUp({
    required String name,
    required String email,
    required String password,
    required String confirmation,
  }) async {
    state = AuthState(
      status: AuthStatus.authenticating,
      user: state.user,
      token: state.token,
    );

    try {
      final AuthSession session = await _repository.register(
        name: name,
        email: email,
        password: password,
        confirmation: confirmation,
      );
      _applySession(session);

      return true;
    } on ApiFailure catch (failure) {
      state = AuthState(failure: failure);

      return false;
    } on Object {
      state = const AuthState(failure: ApiFailure.generic);

      return false;
    }
  }

  Future<void> signOut() async {
    try {
      if (state.token != null) {
        await _repository.logout();
      }
    } on Object {
      // Revocation is best effort: the local session still ends, so a later
      // screen cannot keep using a token the user asked to drop (F-08).
      _clearSession();

      return;
    }

    _clearSession();
  }

  /// Rehydrates the profile when a token already exists. In this slice the
  /// token is memory-only, so a cold start never has one.
  Future<void> restore() async {
    final String? token = state.token;

    if (token == null || token.isEmpty) {
      return;
    }

    ref.read(apiClientProvider).token = token;

    try {
      final MeResponse me = await _repository.me();
      state = AuthState(
        status: AuthStatus.authenticated,
        user: me.user,
        token: token,
      );
    } on ApiFailure catch (failure) {
      if (failure.isUnauthenticated) {
        _clearSession();

        return;
      }

      state = AuthState(
        status: AuthStatus.authenticated,
        user: state.user,
        token: token,
        failure: failure,
      );
    }
  }

  void _applySession(AuthSession session) {
    ref.read(apiClientProvider).token = session.token;
    state = AuthState(
      status: AuthStatus.authenticated,
      user: session.user,
      token: session.token,
    );
  }

  void _clearSession() {
    ref.read(apiClientProvider).token = null;
    state = const AuthState();
  }
}

final NotifierProvider<AuthController, AuthState> authControllerProvider =
    NotifierProvider<AuthController, AuthState>(AuthController.new);
