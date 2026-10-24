import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../network/api_client.dart';
import '../network/json_read.dart';
import '../network/network_providers.dart';
import 'notification_models.dart';

/// The actor's own notification ledger and communication preferences.
///
/// These endpoints are `/me`-scoped, not workspace-scoped: the server filters
/// by the authenticated actor and never trusts a user id from the client.
class NotificationsRepository {
  NotificationsRepository(this._api);

  final ApiClient _api;

  Future<List<NotificationDelivery>> listDeliveries({
    String? status,
    String? channel,
    bool? unread,
    int? limit,
  }) async {
    final Map<String, dynamic> query = <String, dynamic>{};
    final String? statusFilter = status;
    final String? channelFilter = channel;
    final bool? unreadFilter = unread;
    final int? limitFilter = limit;

    if (statusFilter != null && statusFilter.isNotEmpty) {
      query['status'] = statusFilter;
    }

    if (channelFilter != null && channelFilter.isNotEmpty) {
      query['channel'] = channelFilter;
    }

    if (unreadFilter != null) {
      query['unread'] = unreadFilter;
    }

    if (limitFilter != null) {
      query['limit'] = limitFilter;
    }

    final Map<String, dynamic> body = await _api.get(
      '/me/notifications/deliveries',
      query: query.isEmpty ? null : query,
    );

    return readList(body['data'])
        .map((Object? entry) => NotificationDelivery.fromJson(readMap(entry)))
        .where((NotificationDelivery delivery) => delivery.id.isNotEmpty)
        .toList(growable: false);
  }

  Future<NotificationDelivery> markRead(String id) async {
    final Map<String, dynamic> body = await _api.post(
      '/me/notifications/$id/read',
    );

    return NotificationDelivery.fromJson(readMap(body['data']));
  }

  Future<NotificationPreferences> getPreferences() async {
    final Map<String, dynamic> body = await _api.get(
      '/me/notification-preferences',
    );

    return NotificationPreferences.fromJson(readMap(body['data']));
  }

  Future<NotificationPreferences> patchPreferences(
    NotificationPreferencesPatch changes,
  ) async {
    final Map<String, dynamic> body = await _api.patch(
      '/me/notification-preferences',
      data: changes.toJson(),
    );

    return NotificationPreferences.fromJson(readMap(body['data']));
  }
}

final Provider<NotificationsRepository> notificationRepositoryProvider =
    Provider<NotificationsRepository>(
      (Ref ref) => NotificationsRepository(ref.watch(apiClientProvider)),
    );
