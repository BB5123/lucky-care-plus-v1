import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../design_system/tokens.dart';

/// The five-tab shell from `.ai/MOBILE_DESIGN.md` §3.
///
/// It owns no data: [navigationShell] keeps one navigation stack per branch and
/// each tab page loads its own controller.
class AppShell extends StatelessWidget {
  const AppShell({super.key, required this.navigationShell});

  final StatefulNavigationShell navigationShell;

  static const Key navigationKey = Key('app-shell-navigation');

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: navigationShell,
      bottomNavigationBar: NavigationBar(
        key: navigationKey,
        selectedIndex: navigationShell.currentIndex,
        backgroundColor: AppColors.surfaceRaised,
        indicatorColor: AppColors.infoSurface,
        onDestinationSelected: (int index) {
          navigationShell.goBranch(
            index,
            // Tapping the active tab returns it to its first route.
            initialLocation: index == navigationShell.currentIndex,
          );
        },
        destinations: const <NavigationDestination>[
          NavigationDestination(
            icon: Icon(Icons.home_outlined),
            selectedIcon: Icon(Icons.home),
            label: 'ภาพรวม',
          ),
          NavigationDestination(
            icon: Icon(Icons.monitor_heart_outlined),
            selectedIcon: Icon(Icons.monitor_heart),
            label: 'สุขภาพ',
          ),
          NavigationDestination(
            icon: Icon(Icons.checklist_rtl_outlined),
            selectedIcon: Icon(Icons.checklist_rtl),
            label: 'งาน',
          ),
          NavigationDestination(
            icon: Icon(Icons.event_outlined),
            selectedIcon: Icon(Icons.event),
            label: 'นัดหมาย',
          ),
          NavigationDestination(
            icon: Icon(Icons.people_outline),
            selectedIcon: Icon(Icons.people),
            label: 'ครอบครัว',
          ),
        ],
      ),
    );
  }
}
