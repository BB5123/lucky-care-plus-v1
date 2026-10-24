import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../workspace/current_workspace.dart';
import 'data_export_models.dart';
import 'data_export_repository.dart';

/// How often a queued/building export is asked for its status while the card
/// is visible. Mirrors the web card so both clients poll at the same rate.
const Duration dataExportPollInterval = Duration(seconds: 4);

/// The five lifecycle states the card renders. Server-only states (`expired`,
/// `revoked`) are folded into the closest calm state instead of inventing a
/// sixth visual state.
enum ExportPhase { idle, queued, building, ready, failed }

class ExportState {
  const ExportState({
    this.phase = ExportPhase.idle,
    this.exportId,
    this.byteSize,
    this.expiresAt,
    this.requesting = false,
    this.refreshing = false,
    this.downloading = false,
    this.permissionDenied = false,
    this.expired = false,
    this.rateLimitedSeconds,
    this.errorMessage,
    this.linkUrl,
    this.linkExpiresAt,
    this.linkCopied = false,
    this.linkCopyFailed = false,
  });

  final ExportPhase phase;
  final String? exportId;
  final int? byteSize;
  final String? expiresAt;

  final bool requesting;
  final bool refreshing;
  final bool downloading;

  /// `403`: a decision, rendered as an explanation rather than an error banner.
  final bool permissionDenied;

  /// `410`: the copy expired, so the request action becomes "ขอข้อมูลใหม่".
  final bool expired;

  /// Remaining seconds from a `429`; `null` when the last failure was not a
  /// rate limit.
  final int? rateLimitedSeconds;

  /// A calm sentence for a transport/protocol failure.
  final String? errorMessage;

  final String? linkUrl;
  final String? linkExpiresAt;
  final bool linkCopied;
  final bool linkCopyFailed;

  bool get isPending =>
      phase == ExportPhase.queued || phase == ExportPhase.building;

  bool get isReady => phase == ExportPhase.ready;

  String get requestLabel =>
      expired || phase == ExportPhase.failed ? 'ขอข้อมูลใหม่' : 'ขอข้อมูล';

  String get pendingMessage => phase == ExportPhase.queued
      ? 'คำขอของคุณอยู่ในคิว กำลังจัดเตรียมสำเนาข้อมูล...'
      : 'กำลังจัดเตรียมสำเนาข้อมูล...';

  ExportState copyWith({
    ExportPhase? phase,
    Object? exportId = _notProvided,
    Object? byteSize = _notProvided,
    Object? expiresAt = _notProvided,
    bool? requesting,
    bool? refreshing,
    bool? downloading,
    bool? permissionDenied,
    bool? expired,
    Object? rateLimitedSeconds = _notProvided,
    Object? errorMessage = _notProvided,
    Object? linkUrl = _notProvided,
    Object? linkExpiresAt = _notProvided,
    bool? linkCopied,
    bool? linkCopyFailed,
  }) {
    return ExportState(
      phase: phase ?? this.phase,
      exportId: identical(exportId, _notProvided)
          ? this.exportId
          : exportId as String?,
      byteSize: identical(byteSize, _notProvided)
          ? this.byteSize
          : byteSize as int?,
      expiresAt: identical(expiresAt, _notProvided)
          ? this.expiresAt
          : expiresAt as String?,
      requesting: requesting ?? this.requesting,
      refreshing: refreshing ?? this.refreshing,
      downloading: downloading ?? this.downloading,
      permissionDenied: permissionDenied ?? this.permissionDenied,
      expired: expired ?? this.expired,
      rateLimitedSeconds: identical(rateLimitedSeconds, _notProvided)
          ? this.rateLimitedSeconds
          : rateLimitedSeconds as int?,
      errorMessage: identical(errorMessage, _notProvided)
          ? this.errorMessage
          : errorMessage as String?,
      linkUrl: identical(linkUrl, _notProvided)
          ? this.linkUrl
          : linkUrl as String?,
      linkExpiresAt: identical(linkExpiresAt, _notProvided)
          ? this.linkExpiresAt
          : linkExpiresAt as String?,
      linkCopied: linkCopied ?? this.linkCopied,
      linkCopyFailed: linkCopyFailed ?? this.linkCopyFailed,
    );
  }
}

const Object _notProvided = Object();

/// Owns the export lifecycle for the one card on the family page.
///
/// Polling is deliberately tied to [setActive]: the card reports whether it is
/// actually on screen (the shell keeps every tab alive in an `IndexedStack`),
/// so a background tab never keeps asking the API. The timer stops at `ready`
/// and `failed`, and no request outlives its widget.
class DataExportController extends Notifier<ExportState> {
  DataExportController(this.profileId);

  /// The profile this controller instance is bound to. The provider is a
  /// family, so switching profiles resolves to a fresh controller instead of
  /// carrying an id from another profile.
  final String profileId;

  Timer? _pollTimer;
  bool _active = true;
  int _generation = 0;

  @override
  ExportState build() {
    ref.onDispose(() {
      _generation += 1;
      _stopPolling();
    });

    return const ExportState();
  }

  void setActive(bool active) {
    if (_active == active) {
      return;
    }

    _active = active;

    if (!active) {
      _stopPolling();
      return;
    }

    if (state.isPending) {
      _schedulePoll();
    }
  }

  Future<void> requestExport() async {
    if (state.requesting || state.isPending || state.isReady) {
      return;
    }

    final int generation = ++_generation;

    _stopPolling();
    state = state.copyWith(
      phase: ExportPhase.idle,
      exportId: null,
      byteSize: null,
      expiresAt: null,
      requesting: true,
      permissionDenied: false,
      expired: false,
      rateLimitedSeconds: null,
      errorMessage: null,
      linkUrl: null,
      linkExpiresAt: null,
      linkCopied: false,
      linkCopyFailed: false,
    );

    try {
      final ExportRequestResult result = await ref
          .read(dataExportRepositoryProvider)
          .requestExport(await _context(), profileId);

      if (!_isCurrent(generation)) {
        return;
      }

      _applyStatus(result.status, id: result.id);
    } on ExportFailure catch (failure) {
      if (!_isCurrent(generation)) {
        return;
      }

      _applyFailure(failure, whileReady: false);
    } on Object {
      if (!_isCurrent(generation)) {
        return;
      }

      _applyFailure(const ExportUnavailable(), whileReady: false);
    } finally {
      if (_isCurrent(generation)) {
        state = state.copyWith(requesting: false);
      }
    }
  }

  /// Manual "ตรวจสอบสถานะ" while the request is queued/building.
  Future<void> refresh() async {
    final String? id = state.exportId;

    if (id == null || state.refreshing) {
      return;
    }

    await _loadStatus(id);
  }

  /// Mints the short-lived link. The card shows it in a selectable field
  /// because `url_launcher` is not a dependency of this app.
  Future<void> download() async {
    final String? id = state.exportId;

    if (id == null || state.downloading) {
      return;
    }

    final int generation = _generation;

    state = state.copyWith(
      downloading: true,
      errorMessage: null,
      linkUrl: null,
      linkExpiresAt: null,
      linkCopied: false,
      linkCopyFailed: false,
    );

    try {
      final ExportLink link = await ref
          .read(dataExportRepositoryProvider)
          .exportLink(await _context(), profileId, id);

      if (!_isCurrent(generation)) {
        return;
      }

      state = state.copyWith(
        downloading: false,
        linkUrl: link.url,
        linkExpiresAt: link.expiresAt,
      );
    } on ExportFailure catch (failure) {
      if (!_isCurrent(generation)) {
        return;
      }

      _applyFailure(failure, whileReady: true);
    } on Object {
      if (!_isCurrent(generation)) {
        return;
      }

      _applyFailure(const ExportUnavailable(), whileReady: true);
    } finally {
      if (_isCurrent(generation)) {
        state = state.copyWith(downloading: false);
      }
    }
  }

  /// Records the clipboard result so the card can confirm or explain a failure.
  void markLinkCopied(bool copied) {
    state = state.copyWith(linkCopied: copied, linkCopyFailed: !copied);
  }

  Future<void> _loadStatus(String id) async {
    final int generation = _generation;

    _stopPolling();
    state = state.copyWith(refreshing: true, errorMessage: null);

    try {
      final ExportSnapshot snapshot = await ref
          .read(dataExportRepositoryProvider)
          .exportStatus(await _context(), profileId, id);

      if (!_isCurrent(generation)) {
        return;
      }

      _applyStatus(
        snapshot.status,
        id: snapshot.id.isEmpty ? id : snapshot.id,
        snapshot: snapshot,
      );
    } on ExportFailure catch (failure) {
      if (!_isCurrent(generation)) {
        return;
      }

      _applyFailure(failure, whileReady: false);
    } on Object {
      if (!_isCurrent(generation)) {
        return;
      }

      _applyFailure(const ExportUnavailable(), whileReady: false);
    } finally {
      if (_isCurrent(generation)) {
        state = state.copyWith(refreshing: false);

        if (state.isPending) {
          _schedulePoll();
        }
      }
    }
  }

  void _applyStatus(
    String status, {
    required String id,
    ExportSnapshot? snapshot,
  }) {
    if (status == 'expired') {
      _applyExpired();
      return;
    }

    if (status == 'revoked') {
      _applyNotPermitted();
      return;
    }

    final ExportPhase phase = switch (status) {
      'queued' => ExportPhase.queued,
      'building' => ExportPhase.building,
      'ready' => ExportPhase.ready,
      'failed' => ExportPhase.failed,
      _ => ExportPhase.failed,
    };
    final bool ready = phase == ExportPhase.ready;

    state = state.copyWith(
      phase: phase,
      exportId: id,
      byteSize: ready ? snapshot?.byteSize : null,
      expiresAt: ready ? snapshot?.expiresAt : null,
      permissionDenied: false,
      expired: false,
      rateLimitedSeconds: null,
      errorMessage: null,
      linkUrl: ready ? state.linkUrl : null,
      linkExpiresAt: ready ? state.linkExpiresAt : null,
      linkCopied: ready ? state.linkCopied : false,
      linkCopyFailed: ready ? state.linkCopyFailed : false,
    );

    if (phase == ExportPhase.ready || phase == ExportPhase.failed) {
      _stopPolling();
    } else {
      _schedulePoll();
    }
  }

  void _applyFailure(ExportFailure failure, {required bool whileReady}) {
    _generation += 1;
    _stopPolling();

    if (failure is ExportRateLimited) {
      state = ExportState(rateLimitedSeconds: failure.retryAfterSeconds);
      return;
    }

    if (failure is ExportNotPermitted) {
      state = const ExportState(permissionDenied: true);
      return;
    }

    if (failure is ExportExpired) {
      _applyExpired();
      return;
    }

    if (failure is ExportNotReady) {
      state = state.copyWith(
        phase: ExportPhase.building,
        requesting: false,
        refreshing: false,
        downloading: false,
        permissionDenied: false,
        expired: false,
        rateLimitedSeconds: null,
        errorMessage: null,
        linkCopied: false,
        linkCopyFailed: false,
      );
      _schedulePoll();
      return;
    }

    if (whileReady && state.isReady) {
      state = state.copyWith(
        requesting: false,
        refreshing: false,
        downloading: false,
        errorMessage: failure.message,
        linkCopied: false,
        linkCopyFailed: false,
      );
      return;
    }

    state = state.copyWith(
      phase: ExportPhase.failed,
      requesting: false,
      refreshing: false,
      downloading: false,
      permissionDenied: false,
      expired: false,
      rateLimitedSeconds: null,
      errorMessage: failure.message,
      linkUrl: null,
      linkExpiresAt: null,
      linkCopied: false,
      linkCopyFailed: false,
    );
  }

  void _applyExpired() {
    _generation += 1;
    _stopPolling();
    state = const ExportState(expired: true);
  }

  void _applyNotPermitted() {
    _generation += 1;
    _stopPolling();
    state = const ExportState(permissionDenied: true);
  }

  bool _isCurrent(int generation) => ref.mounted && generation == _generation;

  Future<WorkspaceContext> _context() {
    return ref.read(currentWorkspaceProvider.future);
  }

  void _schedulePoll() {
    _stopPolling();

    if (!_active || !state.isPending || state.exportId == null) {
      return;
    }

    _pollTimer = Timer(dataExportPollInterval, () {
      _pollTimer = null;
      final String? id = state.exportId;

      if (id != null) {
        unawaited(_loadStatus(id));
      }
    });
  }

  void _stopPolling() {
    _pollTimer?.cancel();
    _pollTimer = null;
  }
}

final dataExportControllerProvider =
    NotifierProvider.family<DataExportController, ExportState, String>(
      DataExportController.new,
      isAutoDispose: true,
    );
