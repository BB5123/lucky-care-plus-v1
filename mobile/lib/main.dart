import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import 'core/preferences/elder_mode.dart';
import 'core/router/app_router.dart';
import 'design_system/app_theme.dart';
import 'design_system/tokens.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // The elder-mode choice is read once before the first frame so the app never
  // flashes the dense five-zone dashboard for a user who chose large type.
  final ProviderContainer container = ProviderContainer();
  await container.read(elderModeControllerProvider.notifier).restore();

  runApp(
    UncontrolledProviderScope(
      container: container,
      child: const LuckyCareApp(),
    ),
  );
}

class LuckyCareApp extends ConsumerWidget {
  const LuckyCareApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final GoRouter router = ref.watch(routerProvider);
    final bool elderMode = ref.watch(elderModeControllerProvider);

    return MaterialApp.router(
      title: 'LuckyCare+',
      debugShowCheckedModeBanner: false,
      theme: buildAppTheme(elderMode: elderMode),
      routerConfig: router,
      builder: (BuildContext context, Widget? child) {
        final Widget app = child ?? const SizedBox.shrink();

        if (!elderMode) {
          return app;
        }

        // Body text across every tab moves one token step up. The theme
        // handles Material components; this handles the explicit AppType
        // styles the feature screens use.
        return MediaQuery(
          data: MediaQuery.of(context).copyWith(
            textScaler: ElderModeTextScaler(
              MediaQuery.textScalerOf(context),
            ),
          ),
          child: app,
        );
      },
    );
  }
}
