import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/workspace/current_workspace.dart';
import 'health_models.dart';
import 'health_repository.dart';

/// Loads the current profile's readings, newest first.
///
/// The state carries an accumulated cursor window. The first page is a full
/// screen state; later pages are appended and their failures stay in the
/// footer, so a flaky "load more" never hides the rows already read.
class HealthController extends AsyncNotifier<HealthData> {
  bool _loadMoreInFlight = false;

  /// Bumped whenever the window is replaced (first load, refresh, a new
  /// measurement). A page that was requested before the bump is stale and is
  /// discarded when it arrives.
  int _windowGeneration = 0;

  @override
  Future<HealthData> build() async {
    final HealthRepository repository = ref.watch(healthRepositoryProvider);
    final WorkspaceContext context = await ref.watch(
      currentWorkspaceProvider.future,
    );

    _windowGeneration++;

    if (!context.hasWorkspace || !context.hasProfile) {
      return HealthData.noProfile;
    }

    final HealthPage page = await repository.page(context);
    final PaginationContractException? contractError = page.contractError;

    if (contractError != null) {
      // Do not render an empty history when the pagination envelope is
      // missing: fail closed with a typed, retryable error instead.
      throw contractError;
    }

    return HealthData(
      hasProfile: true,
      measurements: page.items,
      nextCursor: page.nextCursor,
    );
  }

  void retry() {
    ref.invalidateSelf();
  }

  /// Appends the next cursor page.
  ///
  /// Idempotent: a second call while a request is in flight, or after the last
  /// page, is ignored. Duplicate ids are dropped because a row created while
  /// the user scrolls can shift the cursor window.
  Future<void> loadMore() async {
    if (_loadMoreInFlight) {
      return;
    }

    final HealthData? current = state.value;

    if (current == null || !current.hasProfile) {
      return;
    }

    final String? cursor = current.nextCursor;

    if (cursor == null || cursor.isEmpty) {
      // A footer retry is still meaningful when the first page after a write
      // could not be read (no cursor was stored): start the window over.
      if (current.loadMoreError != null) {
        ref.invalidateSelf();
      }

      return;
    }

    final int generation = _windowGeneration;

    // Claim the slot before the first await so two taps in the same frame
    // cannot issue two requests (HPG-T06).
    _loadMoreInFlight = true;
    state = AsyncData<HealthData>(
      HealthData(
        hasProfile: true,
        measurements: current.measurements,
        nextCursor: cursor,
        isLoadingMore: true,
      ),
    );

    try {
      final WorkspaceContext context = await ref.read(
        currentWorkspaceProvider.future,
      );

      // The window may have been replaced while the context resolved.
      if (generation != _windowGeneration) {
        return;
      }

      final HealthPage page = await ref
          .read(healthRepositoryProvider)
          .page(context, cursor: cursor);

      if (generation != _windowGeneration) {
        return;
      }

      final HealthData latest = state.value ?? current;
      final PaginationContractException? contractError = page.contractError;

      if (contractError != null) {
        // Keep the cursor so "ลองใหม่" retries the same window; the rows that
        // did arrive are still appended, de-duplicated by id.
        state = AsyncData<HealthData>(
          HealthData(
            hasProfile: true,
            measurements: _merge(latest.measurements, page.items),
            nextCursor: cursor,
            loadMoreError: contractError,
          ),
        );

        return;
      }

      state = AsyncData<HealthData>(
        HealthData(
          hasProfile: true,
          measurements: _merge(latest.measurements, page.items),
          nextCursor: page.nextCursor,
        ),
      );
    } catch (error) {
      if (generation != _windowGeneration) {
        return;
      }

      final HealthData latest = state.value ?? current;

      // Keep the rows and the cursor: the footer offers a retry instead of
      // turning the whole screen into an error state.
      state = AsyncData<HealthData>(
        HealthData(
          hasProfile: true,
          measurements: latest.measurements,
          nextCursor: cursor,
          loadMoreError: error,
        ),
      );
    } finally {
      _loadMoreInFlight = false;
    }
  }

  /// Saves a reading and resets the list to a fresh first page.
  ///
  /// The accumulated cursor window is discarded: a new row shifts every cursor
  /// page, so appending to the old window would skip or duplicate history.
  Future<void> record(CreateMeasurementInput input) async {
    final WorkspaceContext context = await ref.read(
      currentWorkspaceProvider.future,
    );
    final HealthRepository repository = ref.read(healthRepositoryProvider);
    final HealthMeasurement created = await repository.create(context, input);

    _windowGeneration++;

    // Keep the just-created row visible, but drop every accumulated page
    // before the first page is fetched again.
    state = AsyncData<HealthData>(
      HealthData(hasProfile: true, measurements: <HealthMeasurement>[created]),
    );

    try {
      final HealthPage first = await repository.page(context);
      final PaginationContractException? contractError = first.contractError;

      state = AsyncData<HealthData>(
        HealthData(
          hasProfile: true,
          measurements: <HealthMeasurement>[created, ...first.items],
          nextCursor: contractError == null ? first.nextCursor : null,
          loadMoreError: contractError,
        ),
      );
    } catch (error) {
      // The write succeeded. Keep the created row on screen and expose the
      // failed refresh in the footer, never as a false "save failed" result.
      state = AsyncData<HealthData>(
        HealthData(
          hasProfile: true,
          measurements: <HealthMeasurement>[created],
          loadMoreError: error,
        ),
      );
    }
  }

  /// Appends pages while keeping the first occurrence of each id.
  static List<HealthMeasurement> _merge(
    List<HealthMeasurement> existing,
    List<HealthMeasurement> incoming,
  ) {
    final Map<String, HealthMeasurement> byId = <String, HealthMeasurement>{};

    for (final HealthMeasurement measurement in existing) {
      byId[measurement.id] = measurement;
    }

    for (final HealthMeasurement measurement in incoming) {
      byId.putIfAbsent(measurement.id, () => measurement);
    }

    return byId.values.toList(growable: false);
  }
}

final AsyncNotifierProvider<HealthController, HealthData>
healthControllerProvider = AsyncNotifierProvider<HealthController, HealthData>(
  HealthController.new,
  isAutoDispose: true,
  retry: (int retryCount, Object error) => null,
);
