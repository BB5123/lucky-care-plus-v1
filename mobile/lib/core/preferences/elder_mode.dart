import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Persistence boundary for the elder-mode preference.
///
/// Tests override this provider with an in-memory implementation, so no test
/// ever reaches the platform channel. The preference is a presentation choice
/// kept on the device; it is never sent to the server.
abstract interface class ElderModeStore {
  Future<bool> read();

  Future<void> write(bool enabled);
}

/// The real store. The key matches the web app's
/// `frontend/src/app/core/preferences/elder-mode.service.ts` so the wording
/// and the persisted choice stay aligned across clients.
class SharedPreferencesElderModeStore implements ElderModeStore {
  const SharedPreferencesElderModeStore();

  static const String storageKey = 'luckycare.elder-mode';

  @override
  Future<bool> read() async {
    try {
      final SharedPreferences preferences = await SharedPreferences.getInstance();
      return preferences.getBool(storageKey) ?? false;
    } on Object {
      // A device without working storage starts in normal mode; the app must
      // still be usable (fail safe, never crash).
      return false;
    }
  }

  @override
  Future<void> write(bool enabled) async {
    try {
      final SharedPreferences preferences = await SharedPreferences.getInstance();
      await preferences.setBool(storageKey, enabled);
    } on Object {
      // The in-memory preference still applies for this session.
    }
  }
}

final Provider<ElderModeStore> elderModeStoreProvider = Provider<ElderModeStore>(
  (Ref ref) => const SharedPreferencesElderModeStore(),
);

/// Elder mode (`.ai/MOBILE_DESIGN.md` §6): large type and a single-focus
/// dashboard. Widgets read the synchronous [enabled] value through the
/// provider; [restore] is awaited once during app startup.
class ElderModeController extends Notifier<bool> {
  @override
  bool build() => false;

  /// Synchronous value for widgets (`ref.watch` also rebuilds them).
  bool get enabled => state;

  /// Loads the persisted choice. Called once before the first frame.
  Future<void> restore() async {
    state = await ref.read(elderModeStoreProvider).read();
  }

  /// Turns elder mode on and persists the choice.
  Future<void> enable() => _set(true);

  /// Turns elder mode off and persists the choice.
  Future<void> disable() => _set(false);

  /// Flips the current value and persists the choice.
  Future<void> toggle() => _set(!state);

  Future<void> _set(bool value) async {
    // Update the tree first: a slow disk must never delay the visible change.
    state = value;
    await ref.read(elderModeStoreProvider).write(value);
  }
}

final NotifierProvider<ElderModeController, bool> elderModeControllerProvider =
    NotifierProvider<ElderModeController, bool>(ElderModeController.new);
