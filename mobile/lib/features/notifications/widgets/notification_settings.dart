import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/notifications/notification_models.dart';
import '../../../core/network/api_failure.dart';
import '../../../design_system/state_views.dart';
import '../../../design_system/tokens.dart';
import '../notifications_controller.dart';

/// The การตั้งค่า section: channel switches, quiet hours, digest and locale.
class NotificationSettingsView extends ConsumerWidget {
  const NotificationSettingsView({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final AsyncValue<NotificationPreferences> state = ref.watch(
      notificationPreferencesControllerProvider,
    );

    return state.when(
      loading: () =>
          const LoadingState(key: Key('notification-preferences-loading')),
      error: (Object error, StackTrace stackTrace) {
        if (error is ApiFailure && error.isPermissionDenied) {
          return const EmptyState(
            key: Key('notification-preferences-permission-state'),
            icon: Icons.lock_outline,
            title: 'คุณยังไม่มีสิทธิ์ดูการตั้งค่าการแจ้งเตือน',
            message: 'หากต้องการจัดการการตั้งค่า กรุณาขอสิทธิ์จากเจ้าของบัญชี',
          );
        }

        return ErrorState(
          key: const Key('notification-preferences-error'),
          message: error is ApiFailure
              ? error.message
              : ApiFailure.genericMessage,
          onRetry: () {
            ref.read(notificationPreferencesControllerProvider.notifier).retry();
          },
        );
      },
      data: (NotificationPreferences preferences) => _SettingsForm(
        key: ValueKey<String>(
          preferences.updatedAt ?? preferences.locale + preferences.digest,
        ),
        preferences: preferences,
      ),
    );
  }
}

class _SettingsForm extends ConsumerStatefulWidget {
  const _SettingsForm({super.key, required this.preferences});

  final NotificationPreferences preferences;

  @override
  ConsumerState<_SettingsForm> createState() => _SettingsFormState();
}

class _SettingsFormState extends ConsumerState<_SettingsForm> {
  final TextEditingController _quietStart = TextEditingController();
  final TextEditingController _quietEnd = TextEditingController();

  late bool _inApp;
  late bool _push;
  late bool _line;
  late bool _quietEnabled;
  late String _locale;
  late String _digest;
  bool _saving = false;
  String? _error;

  @override
  void initState() {
    super.initState();

    final NotificationPreferences preferences = widget.preferences;
    final QuietHours? quiet = preferences.quietHours;

    _inApp = preferences.channels.inApp;
    _push = preferences.channels.push;
    _line = preferences.channels.line;
    _quietEnabled = quiet != null;
    _quietStart.text = quiet?.start ?? '22:00';
    _quietEnd.text = quiet?.end ?? '07:00';
    _locale = preferences.locale;
    _digest = preferences.digest;
  }

  @override
  void dispose() {
    _quietStart.dispose();
    _quietEnd.dispose();
    super.dispose();
  }

  bool _validTime(String value) {
    return RegExp(r'^([01]\d|2[0-3]):[0-5]\d$').hasMatch(value.trim());
  }

  Future<void> _save() async {
    setState(() {
      _error = null;
    });

    final String start = _quietStart.text.trim();
    final String end = _quietEnd.text.trim();

    if (_quietEnabled && (!_validTime(start) || !_validTime(end))) {
      setState(() {
        _error = 'กรุณาระบุเวลาในรูปแบบ HH:MM';
      });

      return;
    }

    final NotificationPreferences next = NotificationPreferences(
      locale: _locale,
      channels: NotificationChannels(
        inApp: _inApp,
        push: _push,
        line: _line,
      ),
      quietHours: _quietEnabled
          ? QuietHours(start: start, end: end)
          : null,
      digest: _digest,
      updatedAt: widget.preferences.updatedAt,
    );

    setState(() {
      _saving = true;
    });

    try {
      await ref
          .read(notificationPreferencesControllerProvider.notifier)
          .save(next);

      if (!mounted) {
        return;
      }

      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('บันทึกการตั้งค่าเรียบร้อย')),
      );
      setState(() {
        _saving = false;
      });
    } on ApiFailure catch (failure) {
      if (!mounted) {
        return;
      }

      setState(() {
        _saving = false;
        _error = failure.message;
      });
    } on Object {
      if (!mounted) {
        return;
      }

      setState(() {
        _saving = false;
        _error = ApiFailure.genericMessage;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      children: <Widget>[
        Expanded(
          child: SingleChildScrollView(
            key: const Key('notification-settings'),
            padding: const EdgeInsets.all(AppSpacing.lg),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
              Text('ช่องทางการแจ้งเตือน', style: AppType.sectionTitle),
              const SizedBox(height: AppSpacing.sm),
              _channelSwitch(
                keyName: 'pref-channel-in_app',
                title: 'ในแอป',
                value: _inApp,
                onChanged: (bool value) {
                  setState(() {
                    _inApp = value;
                  });
                },
              ),
              _channelSwitch(
                keyName: 'pref-channel-push',
                title: 'พุช',
                value: _push,
                onChanged: (bool value) {
                  setState(() {
                    _push = value;
                  });
                },
              ),
              _channelSwitch(
                keyName: 'pref-channel-line',
                title: 'LINE',
                value: _line,
                onChanged: (bool value) {
                  setState(() {
                    _line = value;
                  });
                },
              ),
              const SizedBox(height: AppSpacing.lg),
              Text('ช่วงเวลางดรบกวน', style: AppType.sectionTitle),
              SwitchListTile(
                key: const Key('pref-quiet-enabled'),
                contentPadding: EdgeInsets.zero,
                title: const Text('เปิดช่วงเวลางดรบกวน'),
                value: _quietEnabled,
                onChanged: (bool value) {
                  setState(() {
                    _quietEnabled = value;
                  });
                },
              ),
              if (_quietEnabled)
                Row(
                  children: <Widget>[
                    Expanded(
                      child: TextField(
                        key: const Key('pref-quiet-start'),
                        controller: _quietStart,
                        decoration: const InputDecoration(
                          labelText: 'เริ่ม',
                          hintText: 'HH:MM',
                          border: OutlineInputBorder(),
                        ),
                      ),
                    ),
                    const SizedBox(width: AppSpacing.md),
                    Expanded(
                      child: TextField(
                        key: const Key('pref-quiet-end'),
                        controller: _quietEnd,
                        decoration: const InputDecoration(
                          labelText: 'สิ้นสุด',
                          hintText: 'HH:MM',
                          border: OutlineInputBorder(),
                        ),
                      ),
                    ),
                  ],
                ),
              const SizedBox(height: AppSpacing.lg),
              Text('การสรุปการแจ้งเตือน', style: AppType.sectionTitle),
              const SizedBox(height: AppSpacing.sm),
              SegmentedButton<String>(
                segments: const <ButtonSegment<String>>[
                  ButtonSegment<String>(
                    value: 'immediate',
                    label: Text('ทันที', key: Key('pref-digest-immediate')),
                  ),
                  ButtonSegment<String>(
                    value: 'daily',
                    label: Text('สรุปวันละครั้ง', key: Key('pref-digest-daily')),
                  ),
                ],
                selected: <String>{_digest},
                onSelectionChanged: (Set<String> selection) {
                  setState(() {
                    _digest = selection.first;
                  });
                },
              ),
              const SizedBox(height: AppSpacing.lg),
              Text('ภาษา', style: AppType.sectionTitle),
              const SizedBox(height: AppSpacing.sm),
              SegmentedButton<String>(
                segments: const <ButtonSegment<String>>[
                  ButtonSegment<String>(
                    value: 'th',
                    label: Text('ไทย', key: Key('pref-locale-th')),
                  ),
                  ButtonSegment<String>(
                    value: 'en',
                    label: Text('อังกฤษ', key: Key('pref-locale-en')),
                  ),
                ],
                selected: <String>{_locale},
                onSelectionChanged: (Set<String> selection) {
                  setState(() {
                    _locale = selection.first;
                  });
                },
              ),
            ],
            ),
          ),
        ),
        SafeArea(
          top: false,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(
              AppSpacing.lg,
              AppSpacing.sm,
              AppSpacing.lg,
              AppSpacing.lg,
            ),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                if (_error != null) ...<Widget>[
                  Container(
                    key: const Key('pref-error'),
                    width: double.infinity,
                    padding: const EdgeInsets.all(AppSpacing.md),
                    decoration: BoxDecoration(
                      color: AppColors.dangerSurface,
                      borderRadius: BorderRadius.circular(AppRadius.md),
                    ),
                    child: Text(
                      _error!,
                      style: AppType.label.copyWith(color: AppColors.danger),
                    ),
                  ),
                  const SizedBox(height: AppSpacing.sm),
                ],
                FilledButton(
                  key: const Key('pref-save'),
                  onPressed: _saving ? null : _save,
                  style: FilledButton.styleFrom(
                    backgroundColor: AppColors.info,
                    foregroundColor: AppColors.textOnAccent,
                    minimumSize: const Size.fromHeight(AppSpacing.xxxl),
                    textStyle: AppType.button,
                  ),
                  child: Text(_saving ? 'กำลังบันทึก…' : 'บันทึกการตั้งค่า'),
                ),
              ],
            ),
          ),
        ),
      ],
    );
  }

  Widget _channelSwitch({
    required String keyName,
    required String title,
    required bool value,
    required ValueChanged<bool> onChanged,
  }) {
    return SwitchListTile(
      key: Key(keyName),
      contentPadding: EdgeInsets.zero,
      title: Text(title),
      value: value,
      onChanged: onChanged,
    );
  }
}
