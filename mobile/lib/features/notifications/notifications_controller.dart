import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/notifications/notification_models.dart';
import '../../core/notifications/notifications_repository.dart';

/// Loads the actor's delivery ledger, unread first.
class NotificationDeliveriesController
    extends AsyncNotifier<List<NotificationDelivery>> {
  @override
  Future<List<NotificationDelivery>> build() {
    return ref
        .watch(notificationRepositoryProvider)
        .listDeliveries(limit: 100);
  }

  void retry() {
    ref.invalidateSelf();
  }

  /// Marks one delivery read optimistically and rolls the row back if the
  /// server call fails. Other rows stay untouched.
  Future<bool> markRead(NotificationDelivery delivery) async {
    final List<NotificationDelivery>? current = state.value;

    if (current == null || !delivery.isUnread) {
      return true;
    }

    final int index = current.indexWhere(
      (NotificationDelivery entry) => entry.id == delivery.id,
    );

    if (index < 0) {
      return true;
    }

    final NotificationDelivery previous = current[index];
    final String optimisticReadAt = DateTime.now().toIso8601String();
    final List<NotificationDelivery> optimistic = List<NotificationDelivery>.of(
      current,
    );
    optimistic[index] = previous.copyWith(readAt: optimisticReadAt);
    state = AsyncData<List<NotificationDelivery>>(
      List<NotificationDelivery>.unmodifiable(optimistic),
    );

    try {
      final NotificationDelivery updated = await ref
          .read(notificationRepositoryProvider)
          .markRead(previous.id);
      final List<NotificationDelivery> latest =
          state.value ?? optimistic;
      final int at = latest.indexWhere(
        (NotificationDelivery entry) => entry.id == previous.id,
      );

      if (at >= 0) {
        final List<NotificationDelivery> next =
            List<NotificationDelivery>.of(latest);
        next[at] = updated.id.isEmpty
            ? previous.copyWith(readAt: optimisticReadAt)
            : updated;
        state = AsyncData<List<NotificationDelivery>>(
          List<NotificationDelivery>.unmodifiable(next),
        );
      }

      return true;
    } on Object {
      final List<NotificationDelivery> latest = state.value ?? optimistic;
      final int at = latest.indexWhere(
        (NotificationDelivery entry) => entry.id == previous.id,
      );

      if (at >= 0) {
        final List<NotificationDelivery> next = List<NotificationDelivery>.of(
          latest,
        );
        next[at] = previous;
        state = AsyncData<List<NotificationDelivery>>(
          List<NotificationDelivery>.unmodifiable(next),
        );
      }

      return false;
    }
  }
}

final AsyncNotifierProvider<
  NotificationDeliveriesController,
  List<NotificationDelivery>
>
notificationDeliveriesControllerProvider =
    AsyncNotifierProvider<
      NotificationDeliveriesController,
      List<NotificationDelivery>
    >(
      NotificationDeliveriesController.new,
      isAutoDispose: true,
      retry: (int retryCount, Object error) => null,
    );

/// The badge count in the shell. It follows the same ledger the inbox uses, so
/// a mark-read action updates the badge without a second request.
final Provider<int> unreadNotificationCountProvider = Provider<int>((Ref ref) {
  final AsyncValue<List<NotificationDelivery>> deliveries = ref.watch(
    notificationDeliveriesControllerProvider,
  );
  final List<NotificationDelivery>? rows = deliveries.value;

  if (rows == null) {
    return 0;
  }

  return rows.where((NotificationDelivery row) => row.isUnread).length;
});

/// Loads and patches the actor's own preferences.
class NotificationPreferencesController
    extends AsyncNotifier<NotificationPreferences> {
  @override
  Future<NotificationPreferences> build() {
    return ref.watch(notificationRepositoryProvider).getPreferences();
  }

  void retry() {
    ref.invalidateSelf();
  }

  /// Sends only the fields that differ from the loaded preference. Returns
  /// `true` when there was nothing to save.
  Future<bool> save(NotificationPreferences next) async {
    final NotificationPreferences? current = state.value;

    if (current == null) {
      return false;
    }

    final NotificationPreferencesPatch patch = current.diff(next);

    if (patch.isEmpty) {
      return true;
    }

    final NotificationPreferences updated = await ref
        .read(notificationRepositoryProvider)
        .patchPreferences(patch);
    state = AsyncData<NotificationPreferences>(updated);

    return true;
  }
}

final AsyncNotifierProvider<
  NotificationPreferencesController,
  NotificationPreferences
>
notificationPreferencesControllerProvider =
    AsyncNotifierProvider<
      NotificationPreferencesController,
      NotificationPreferences
    >(
      NotificationPreferencesController.new,
      isAutoDispose: true,
      retry: (int retryCount, Object error) => null,
    );
