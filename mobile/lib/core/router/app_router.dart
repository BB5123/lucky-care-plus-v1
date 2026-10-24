import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../features/appointments/appointments_page.dart';
import '../../features/auth/login_page.dart';
import '../../features/auth/register_page.dart';
import '../../features/dashboard/dashboard_page.dart';
import '../../features/family/family_page.dart';
import '../../features/health/health_page.dart';
import '../../features/notifications/notifications_page.dart';
import '../../features/shell/app_shell.dart';
import '../../features/tasks/tasks_page.dart';
import '../auth/auth_controller.dart';
import '../auth/auth_state.dart';

/// App routes and the auth redirect.
///
/// The router listens to [authControllerProvider] through a [ValueNotifier]
/// because `go_router` wants a [Listenable], while Riverpod exposes state
/// streams. Signing out therefore navigates back to `/login` immediately.
///
/// The five tabs live inside one [StatefulShellRoute.indexedStack], so every
/// branch keeps its own navigation stack while the auth redirect stays at the
/// top level.
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
          location == LoginPage.routePath || location == RegisterPage.routePath;

      if (!auth.isAuthenticated) {
        return isAuthRoute ? null : LoginPage.routePath;
      }

      if (isAuthRoute) {
        return DashboardPage.routePath;
      }

      return null;
    },
    routes: <RouteBase>[
      StatefulShellRoute.indexedStack(
        builder: (
          BuildContext context,
          GoRouterState state,
          StatefulNavigationShell navigationShell,
        ) => AppShell(navigationShell: navigationShell),
        branches: <StatefulShellBranch>[
          StatefulShellBranch(
            routes: <RouteBase>[
              GoRoute(
                path: DashboardPage.routePath,
                builder: (BuildContext context, GoRouterState state) =>
                    const DashboardPage(),
              ),
            ],
          ),
          StatefulShellBranch(
            routes: <RouteBase>[
              GoRoute(
                path: HealthPage.routePath,
                builder: (BuildContext context, GoRouterState state) =>
                    const HealthPage(),
              ),
            ],
          ),
          StatefulShellBranch(
            routes: <RouteBase>[
              GoRoute(
                path: TasksPage.routePath,
                builder: (BuildContext context, GoRouterState state) =>
                    const TasksPage(),
              ),
            ],
          ),
          StatefulShellBranch(
            routes: <RouteBase>[
              GoRoute(
                path: AppointmentsPage.routePath,
                builder: (BuildContext context, GoRouterState state) =>
                    const AppointmentsPage(),
              ),
            ],
          ),
          StatefulShellBranch(
            routes: <RouteBase>[
              GoRoute(
                path: FamilyPage.routePath,
                builder: (BuildContext context, GoRouterState state) =>
                    const FamilyPage(),
              ),
            ],
          ),
        ],
      ),
      GoRoute(
        path: NotificationsPage.routePath,
        builder: (BuildContext context, GoRouterState state) =>
            const NotificationsPage(),
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
