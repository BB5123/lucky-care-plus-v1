import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/auth/auth_controller.dart';
import '../../core/preferences/elder_mode.dart';
import '../../design_system/tokens.dart';
import '../notifications/notifications_controller.dart';
import '../notifications/notifications_page.dart';

enum _ShellOverflowAction { notifications, signOut }

/// The five-tab shell from `.ai/MOBILE_DESIGN.md` §3.
///
/// It owns the app bar (elder-mode switch, notifications and sign out) and no
/// data: the [navigationShell] keeps one navigation stack per branch and each
/// tab page loads its own controller.
class AppShell extends ConsumerWidget {
  const AppShell({super.key, required this.navigationShell});

  final StatefulNavigationShell navigationShell;

  static const Key navigationKey = Key('app-shell-navigation');

  static const List<String> _titles = <String>[
    'LuckyCare+',
    'สุขภาพ',
    'งาน',
    'นัดหมาย',
    'ครอบครัว',
  ];

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final int index = navigationShell.currentIndex;
    final int unread = ref.watch(unreadNotificationCountProvider);
    final bool elderMode = ref.watch(elderModeControllerProvider);

    return Scaffold(
      appBar: AppBar(
        backgroundColor: AppColors.surface,
        surfaceTintColor: AppColors.surface,
        title: Text(_titles[index]),
        actions: <Widget>[
          IconButton(
            key: const Key('elder-mode-button'),
            tooltip: 'โหมดหน้าจอย่อ',
            onPressed: () => _showElderModeSheet(context),
            icon: const Icon(Icons.text_fields),
          ),
          // Elder mode allows at most three buttons on screen. The bell and
          // sign-out keep working from the overflow, so nothing is hidden,
          // while the focus card's primary action stays within budget.
          if (elderMode)
            PopupMenuButton<_ShellOverflowAction>(
              key: const Key('shell-overflow'),
              tooltip: 'เพิ่มเติม',
              icon: const Icon(Icons.more_vert),
              onSelected: (_ShellOverflowAction action) {
                switch (action) {
                  case _ShellOverflowAction.notifications:
                    context.push(NotificationsPage.routePath);
                  case _ShellOverflowAction.signOut:
                    ref.read(authControllerProvider.notifier).signOut();
                }
              },
              itemBuilder: (BuildContext context) =>
                  <PopupMenuEntry<_ShellOverflowAction>>[
                    PopupMenuItem<_ShellOverflowAction>(
                      value: _ShellOverflowAction.notifications,
                      child: Text(
                        unread > 0
                            ? 'การแจ้งเตือน ($unread)'
                            : 'การแจ้งเตือน',
                      ),
                    ),
                    const PopupMenuItem<_ShellOverflowAction>(
                      value: _ShellOverflowAction.signOut,
                      child: Text('ออกจากระบบ'),
                    ),
                  ],
            )
          else ...<Widget>[
            IconButton(
              key: const Key('notification-bell'),
              tooltip: 'การแจ้งเตือน',
              onPressed: () => context.push(NotificationsPage.routePath),
              icon: unread > 0
                  ? Badge(
                      key: const Key('notification-badge'),
                      label: Text('$unread'),
                      child: const Icon(Icons.notifications_outlined),
                    )
                  : const Icon(Icons.notifications_outlined),
            ),
            IconButton(
              key: const Key('logout-button'),
              tooltip: 'ออกจากระบบ',
              icon: const Icon(Icons.logout),
              onPressed: () {
                ref.read(authControllerProvider.notifier).signOut();
              },
            ),
          ],
        ],
      ),
      body: navigationShell,
      bottomNavigationBar: NavigationBar(
        key: navigationKey,
        selectedIndex: index,
        backgroundColor: AppColors.surfaceRaised,
        indicatorColor: AppColors.infoSurface,
        onDestinationSelected: (int nextIndex) {
          navigationShell.goBranch(
            nextIndex,
            // Tapping the active tab returns it to its first route.
            initialLocation: nextIndex == index,
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

  Future<void> _showElderModeSheet(BuildContext context) {
    return showModalBottomSheet<void>(
      context: context,
      showDragHandle: true,
      backgroundColor: AppColors.surfaceRaised,
      builder: (BuildContext context) => const _ElderModeSheet(),
    );
  }
}

/// The small settings sheet from `.ai/MOBILE_DESIGN.md` §6. The switch is the
/// same wording as the web app, and changing it updates the dashboard in the
/// same frame because the dashboard watches [elderModeControllerProvider].
class _ElderModeSheet extends ConsumerWidget {
  const _ElderModeSheet();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final bool enabled = ref.watch(elderModeControllerProvider);

    return SafeArea(
      child: SwitchListTile(
        key: const Key('elder-mode-switch'),
        value: enabled,
        onChanged: (bool next) async {
          final ElderModeController controller = ref.read(
            elderModeControllerProvider.notifier,
          );

          if (next) {
            await controller.enable();
          } else {
            await controller.disable();
          }
        },
        title: const Text('โหมดหน้าจอย่อ'),
        subtitle: const Text('ตัวอักษรใหญ่ขึ้น แสดงเฉพาะสิ่งที่ต้องทำตอนนี้'),
        secondary: const Icon(Icons.text_fields),
      ),
    );
  }
}
