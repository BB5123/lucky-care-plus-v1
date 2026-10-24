import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../features/auth/login_page.dart';
import '../../features/auth/register_page.dart';
import '../../features/dashboard/dashboard_page.dart';
import '../auth/auth_controller.dart';
import '../auth/auth_state.dart';

/// App routes and the auth redirect.
///
/// The router listens to [authControllerProvider] through a [ValueNotifier]
/// because `go_router` wants a [Listenable], while Riverpod exposes state
/// streams. Signing out therefore navigates back to `/login` immediately.
final Provider<GoRouter> routerProvider = Provider<GoRouter>((Ref ref) {
  final ValueNotifier<int> authRevision = ValueNotifier<int>(0);

  ref.onDispose(authRevision.dispose);

  ref.listen<AuthState>(authControllerProvider, (
    AuthState? previous,
    AuthState next,
  ) {
    authRevision.value += 1;
  });

  return GoRouter(
    initialLocation: DashboardPage.routePath,
    refreshListenable: authRevision,
    redirect: (BuildContext context, GoRouterState state) {
      final AuthState auth = ref.read(authControllerProvider);
      final String location = state.matchedLocation;
      final bool isAuthRoute =
          location == LoginPage.routePath ||
          location == RegisterPage.routePath;

      if (!auth.isAuthenticated) {
        return isAuthRoute ? null : LoginPage.routePath;
      }

      if (isAuthRoute) {
        return DashboardPage.routePath;
      }

      return null;
    },
    routes: <RouteBase>[
      GoRoute(
        path: DashboardPage.routePath,
        builder: (BuildContext context, GoRouterState state) =>
            const DashboardPage(),
      ),
      GoRoute(
        path: LoginPage.routePath,
        builder: (BuildContext context, GoRouterState state) =>
            const LoginPage(),
      ),
      GoRoute(
        path: RegisterPage.routePath,
        builder: (BuildContext context, GoRouterState state) =>
            const RegisterPage(),
      ),
    ],
    errorBuilder: (BuildContext context, GoRouterState state) =>
        const LoginPage(),
  );
});
