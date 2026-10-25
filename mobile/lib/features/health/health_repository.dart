import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/network/api_client.dart';
import '../../core/network/api_failure.dart';
import '../../core/network/json_read.dart';
import '../../core/network/network_providers.dart';
import '../../core/workspace/current_workspace.dart';
import 'health_models.dart';

/// One cursor page of `GET .../measurements`.
///
/// [nextCursor] is `null` on the last page. [contractError] is set when the
/// server answered with a body that does not carry the documented `meta`
/// envelope: the rows are still usable, but the client must not pretend the
/// history ended there, so the caller can surface the typed error.
class HealthPage {
  const HealthPage({required this.items, this.nextCursor, this.contractError});

  final List<HealthMeasurement> items;
  final String? nextCursor;
  final PaginationContractException? contractError;

  bool get hasMore => nextCursor != null;
}

/// The server sent a list body that does not match the pagination contract.
///
/// A typed exception is deliberate: a missing `meta.next_cursor` would
/// otherwise look like "this is the last page" and silently truncate history.
class PaginationContractException implements Exception {
  const PaginationContractException([
    this.message = 'รูปแบบข้อมูลแบ่งหน้าจากเซิร์ฟเวอร์ไม่ถูกต้อง',
  ]);

  /// Thai sentence that is safe to render.
  final String message;

  @override
  String toString() => 'PaginationContractException: $message';
}

/// Reads and writes measurements for the workspace's current profile.
///
/// It never filters by permission: a 403/404 is surfaced as a decision and the
/// page renders an explanation instead of an error banner.
class HealthRepository {
  HealthRepository(this._api);

  /// Page size used when the caller does not ask for one.
  static const int defaultPageSize = 50;

  /// `ListMeasurementsRequest` rejects `limit > 100`, so the client clamps
  /// before sending instead of relying on a 422.
  static const int serverLimitCap = 100;

  final ApiClient _api;

  /// Fetches one cursor page, newest first.
  ///
  /// The cursor is opaque: it is echoed back exactly as the server produced it.
  Future<HealthPage> page(
    WorkspaceContext context, {
    String? cursor,
    int limit = defaultPageSize,
  }) async {
    final Map<String, dynamic> query = <String, dynamic>{
      'limit': _clampLimit(limit),
    };
    final String? trimmedCursor = cursor?.trim();

    if (trimmedCursor != null && trimmedCursor.isNotEmpty) {
      query['cursor'] = trimmedCursor;
    }

    final Map<String, dynamic> body = await _api.get(
      '${_profilePath(context)}/measurements',
      query: query,
    );
    final List<HealthMeasurement> items = readList(body['data'])
        .map((Object? entry) => HealthMeasurement.fromJson(readMap(entry)))
        .where((HealthMeasurement measurement) => measurement.id.isNotEmpty)
        .toList(growable: false);
    final Object? rawMeta = body['meta'];
    final Map<String, dynamic> meta = rawMeta is Map
        ? readMap(rawMeta)
        : const <String, dynamic>{};

    if (rawMeta is! Map ||
        !meta.containsKey('next_cursor') ||
        !_isCursorValue(meta['next_cursor']) ||
        _malformedLimit(meta['limit'])) {
      return HealthPage(
        items: items,
        contractError: const PaginationContractException(),
      );
    }

    return HealthPage(
      items: items,
      nextCursor: readNullableString(meta['next_cursor']),
    );
  }

  /// First page only. Kept for call sites that do not paginate yet.
  Future<List<HealthMeasurement>> list(WorkspaceContext context) async {
    final HealthPage first = await page(context);

    return first.items;
  }

  Future<HealthMeasurement> create(
    WorkspaceContext context,
    CreateMeasurementInput input,
  ) async {
    final Map<String, dynamic> body = await _api.post(
      '${_profilePath(context)}/measurements',
      data: input.toJson(),
    );

    return HealthMeasurement.fromJson(readMap(body['data']));
  }

  String _profilePath(WorkspaceContext context) {
    final String? workspaceId = context.workspaceId;
    final String? profileId = context.profileId;

    if (workspaceId == null ||
        workspaceId.isEmpty ||
        profileId == null ||
        profileId.isEmpty) {
      throw const ApiFailure(
        status: 404,
        code: 'out_of_scope',
        message: 'ไม่พบข้อมูลที่ร้องขอ',
      );
    }

    return '/workspaces/$workspaceId/profiles/$profileId';
  }

  static int _clampLimit(int limit) {
    if (limit < 1) {
      return 1;
    }

    if (limit > serverLimitCap) {
      return serverLimitCap;
    }

    return limit;
  }

  /// The contract says `next_cursor` is a string or `null` — nothing else.
  static bool _isCursorValue(Object? value) {
    return value == null || value is String;
  }

  /// `limit` is part of the envelope but a client that already clamped its
  /// request must not fail when the server omits it. A present value still has
  /// to be a real page size.
  static bool _malformedLimit(Object? value) {
    if (value == null) {
      return false;
    }

    return readInt(value, fallback: 0) < 1;
  }
}

final Provider<HealthRepository> healthRepositoryProvider =
    Provider<HealthRepository>(
      (Ref ref) => HealthRepository(ref.watch(apiClientProvider)),
    );
