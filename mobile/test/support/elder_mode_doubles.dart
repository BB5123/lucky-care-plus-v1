import 'package:lucky_care/core/preferences/elder_mode.dart';

/// In-memory [ElderModeStore]. Widget and controller tests never touch the
/// shared_preferences platform channel.
class FakeElderModeStore implements ElderModeStore {
  FakeElderModeStore({this.value = false});

  bool value;
  int reads = 0;
  int writes = 0;

  @override
  Future<bool> read() async {
    reads++;
    return value;
  }

  @override
  Future<void> write(bool enabled) async {
    writes++;
    value = enabled;
  }
}
