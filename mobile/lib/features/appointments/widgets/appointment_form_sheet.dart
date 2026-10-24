import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/format/thai_date.dart';
import '../../../core/network/api_failure.dart';
import '../../../design_system/tokens.dart';
import '../appointment_models.dart';
import '../appointments_controller.dart';

/// Create/edit sheet for one appointment.
///
/// A `412` does not retry the stale write: the sheet shows the stale notice
/// and, on **โหลดใหม่**, refetches and asks [show] to reopen the sheet with the
/// fresh row. Create/save/cancel each close the sheet on success; the
/// controller has already refreshed the list data at that point.
class AppointmentFormSheet extends ConsumerStatefulWidget {
  const AppointmentFormSheet({
    super.key,
    this.appointment,
    this.initialStartsAt,
    this.now,
  });

  /// `null` for a new appointment, a row for edit.
  final Appointment? appointment;

  /// Test/preview override for the initial date-time of a new appointment.
  final DateTime? initialStartsAt;

  /// Injectable clock so the future/past rule is testable.
  final DateTime Function()? now;

  static Future<void> show(
    BuildContext context, {
    Appointment? appointment,
    DateTime? initialStartsAt,
    DateTime Function()? now,
  }) async {
    Appointment? current = appointment;

    while (true) {
      if (!context.mounted) {
        return;
      }

      final Appointment? reopen = await showModalBottomSheet<Appointment>(
        context: context,
        isScrollControlled: true,
        useSafeArea: true,
        backgroundColor: AppColors.surfaceRaised,
        builder: (BuildContext context) => AppointmentFormSheet(
          appointment: current,
          initialStartsAt: current == null ? initialStartsAt : null,
          now: now,
        ),
      );

      // The only non-null result is a refreshed row after a 412; reopening
      // keeps the user on the same form with the server's current values.
      if (reopen == null) {
        return;
      }

      current = reopen;
    }
  }

  @override
  ConsumerState<AppointmentFormSheet> createState() =>
      _AppointmentFormSheetState();
}

class _AppointmentFormSheetState extends ConsumerState<AppointmentFormSheet> {
  static const Set<String> _knownServerFields = <String>{
    'title',
    'duration_minutes',
    'location',
    'provider_name',
    'purpose',
    'scheduled_at',
    'starts_at',
  };

  final GlobalKey<FormState> _formKey = GlobalKey<FormState>();
  final TextEditingController _title = TextEditingController();
  final TextEditingController _duration = TextEditingController();
  final TextEditingController _location = TextEditingController();
  final TextEditingController _clinician = TextEditingController();
  final TextEditingController _notes = TextEditingController();

  Appointment? _current;
  DateTime? _startsAt;
  Map<String, String> _serverErrors = const <String, String>{};
  String? _startError;
  String? _formError;
  String? _conflictMessage;
  bool _stale = false;
  bool _saving = false;

  @override
  void initState() {
    super.initState();

    final Appointment? appointment = widget.appointment;

    _current = appointment;
    _title.text = appointment?.title ?? '';
    _duration.text = (appointment?.durationMinutes ?? 30).toString();
    _location.text = appointment?.location ?? '';
    _clinician.text = appointment?.providerName ?? '';
    _notes.text = appointment?.notes ?? '';
    _startsAt =
        appointment?.scheduledAtDate?.toLocal() ??
        widget.initialStartsAt?.toLocal() ??
        _defaultStart();
  }

  @override
  void dispose() {
    _title.dispose();
    _duration.dispose();
    _location.dispose();
    _clinician.dispose();
    _notes.dispose();
    super.dispose();
  }

  DateTime _now() => widget.now?.call() ?? DateTime.now();

  DateTime _defaultStart() {
    final DateTime tomorrow = _now().add(const Duration(days: 1));

    return DateTime(tomorrow.year, tomorrow.month, tomorrow.day, 9);
  }

  void _clearServerError(String field) {
    if (!_serverErrors.containsKey(field)) {
      return;
    }

    setState(() {
      final Map<String, String> next = Map<String, String>.of(_serverErrors);
      next.remove(field);
      _serverErrors = next;
    });
  }

  String? _serverError(String field) => _serverErrors[field];

  String? _validateTitle(String? raw) {
    final String? server = _serverError('title');

    if (server != null) {
      return server;
    }

    if ((raw ?? '').trim().isEmpty) {
      return 'กรุณากรอกชื่อนัดหมาย';
    }

    return null;
  }

  String? _validateDuration(String? raw) {
    final String? server = _serverError('duration_minutes');

    if (server != null) {
      return server;
    }

    final String text = (raw ?? '').trim();

    if (text.isEmpty) {
      return 'กรุณากรอกระยะเวลาเป็นนาที';
    }

    final int? minutes = int.tryParse(text);

    if (minutes == null) {
      return 'กรุณากรอกตัวเลข';
    }

    if (minutes < 5 || minutes > 480) {
      return 'ระยะเวลาต้องอยู่ระหว่าง 5 ถึง 480 นาที';
    }

    return null;
  }

  String? _validateStart() {
    final String? server =
        _serverError('scheduled_at') ?? _serverError('starts_at');

    if (server != null) {
      return server;
    }

    final DateTime? startsAt = _startsAt;

    if (startsAt == null) {
      return 'กรุณาเลือกวันและเวลา';
    }

    // Only a new visit must be in the future; an existing historical row may
    // be edited without touching its original date.
    if (_current == null && !startsAt.isAfter(_now())) {
      return 'เวลาเริ่มต้องเป็นเวลาในอนาคต';
    }

    return null;
  }

  Future<void> _pickDate() async {
    final DateTime now = _now();
    final DateTime firstDate = DateTime(now.year - 5);
    final DateTime lastDate = DateTime(now.year + 5);
    final DateTime candidate = _startsAt ?? now.add(const Duration(days: 1));
    final DateTime initialDate = candidate.isBefore(firstDate)
        ? firstDate
        : (candidate.isAfter(lastDate) ? lastDate : candidate);
    final DateTime? picked = await showDatePicker(
      context: context,
      initialDate: initialDate,
      firstDate: firstDate,
      lastDate: lastDate,
      helpText: 'เลือกวันที่นัดหมาย',
      cancelText: 'ยกเลิก',
      confirmText: 'ตกลง',
    );

    if (picked == null || !mounted) {
      return;
    }

    final DateTime? current = _startsAt;

    setState(() {
      _startsAt = DateTime(
        picked.year,
        picked.month,
        picked.day,
        current?.hour ?? 9,
        current?.minute ?? 0,
      );
      _startError = null;
    });
  }

  Future<void> _pickTime() async {
    final TimeOfDay initialTime = _startsAt == null
        ? const TimeOfDay(hour: 9, minute: 0)
        : TimeOfDay.fromDateTime(_startsAt!);
    final TimeOfDay? picked = await showTimePicker(
      context: context,
      initialTime: initialTime,
      helpText: 'เลือกเวลา',
      cancelText: 'ยกเลิก',
      confirmText: 'ตกลง',
    );

    if (picked == null || !mounted) {
      return;
    }

    final DateTime at = _startsAt ?? _defaultStart();

    setState(() {
      _startsAt = DateTime(
        at.year,
        at.month,
        at.day,
        picked.hour,
        picked.minute,
      );
      _startError = null;
    });
  }

  Future<void> _submit() async {
    setState(() {
      _serverErrors = const <String, String>{};
      _formError = null;
      _conflictMessage = null;
      _stale = false;
      _startError = null;
    });

    final bool formValid = _formKey.currentState?.validate() ?? false;
    final String? startError = _validateStart();

    if (!formValid || startError != null) {
      setState(() => _startError = startError);
      return;
    }

    final DateTime startsAt = _startsAt!;
    final int durationMinutes = int.parse(_duration.text.trim());

    setState(() => _saving = true);

    final AppointmentsController controller = ref.read(
      appointmentsControllerProvider.notifier,
    );
    final Appointment? current = _current;
    final AppointmentWriteOutcome outcome;

    if (current == null) {
      outcome = await controller.create(
        title: _title.text.trim(),
        startsAt: startsAt,
        durationMinutes: durationMinutes,
        location: _optional(_location.text),
        clinician: _optional(_clinician.text),
        notes: _optional(_notes.text),
      );
    } else {
      outcome = await controller.updateAppointment(
        current,
        title: _title.text.trim(),
        startsAt: startsAt,
        durationMinutes: durationMinutes,
        location: _optional(_location.text),
        clinician: _optional(_clinician.text),
        notes: _optional(_notes.text),
      );
    }

    if (!mounted) {
      return;
    }

    _handleOutcome(outcome);
  }

  void _handleOutcome(AppointmentWriteOutcome outcome) {
    if (outcome is AppointmentWriteSuccess) {
      Navigator.of(context).pop();
      return;
    }

    if (outcome is StaleAppointment) {
      setState(() {
        _saving = false;
        _stale = true;
        _formError = null;
        _conflictMessage = null;
      });
      return;
    }

    if (outcome is AppointmentValidationFailure) {
      _showValidation(outcome);
      return;
    }

    if (outcome is AppointmentConflict) {
      setState(() {
        _saving = false;
        _stale = false;
        _conflictMessage = outcome.message;
      });
      return;
    }

    if (outcome is AppointmentWriteFailure) {
      setState(() {
        _saving = false;
        _stale = false;
        _formError = outcome.message;
      });
    }
  }

  void _showValidation(AppointmentValidationFailure outcome) {
    final Map<String, String> errors = outcome.fieldErrors;
    final List<String> unknown = errors.entries
        .where(
          (MapEntry<String, String> entry) =>
              !_knownServerFields.contains(entry.key),
        )
        .map((MapEntry<String, String> entry) => entry.value)
        .toList(growable: false);

    setState(() {
      _saving = false;
      _stale = false;
      _conflictMessage = null;
      _serverErrors = errors;
      _startError = errors['scheduled_at'] ?? errors['starts_at'];
      _formError = unknown.isNotEmpty
          ? unknown.first
          : (errors.isEmpty ? outcome.message : null);
    });

    // Re-run the validators so each server sentence appears inside the field
    // it names instead of in a form-level banner.
    _formKey.currentState?.validate();
  }

  Future<void> _reload() async {
    final Appointment? current = _current;

    if (current == null) {
      return;
    }

    setState(() {
      _saving = true;
      _formError = null;
      _conflictMessage = null;
    });

    try {
      final AppointmentsController controller = ref.read(
        appointmentsControllerProvider.notifier,
      );

      await controller.refresh();

      if (!mounted) {
        return;
      }

      final Appointment? fresh = controller.appointmentById(current.id);

      if (fresh == null) {
        setState(() {
          _saving = false;
          _stale = false;
          _formError = 'ไม่พบนัดหมายนี้อีกแล้ว';
        });
        return;
      }

      // [AppointmentFormSheet.show] reopens with this row.
      Navigator.of(context).pop(fresh);
    } on ApiFailure catch (failure) {
      if (!mounted) {
        return;
      }

      setState(() {
        _saving = false;
        _formError = failure.message;
      });
    } on Object {
      if (!mounted) {
        return;
      }

      setState(() {
        _saving = false;
        _formError = ApiFailure.genericMessage;
      });
    }
  }

  Future<void> _confirmCancel() async {
    final Appointment? current = _current;

    if (current == null) {
      return;
    }

    final bool? confirmed = await showDialog<bool>(
      context: context,
      builder: (BuildContext dialogContext) {
        return AlertDialog(
          key: const Key('appointment-cancel-dialog'),
          title: const Text('ยกเลิกนัดหมาย?'),
          content: Text('ต้องการยกเลิก “${current.title}” หรือไม่'),
          actions: <Widget>[
            TextButton(
              key: const Key('appointment-cancel-dismiss'),
              onPressed: () => Navigator.of(dialogContext).pop(false),
              child: const Text('ไม่ใช่'),
            ),
            FilledButton(
              key: const Key('appointment-cancel-confirm'),
              onPressed: () => Navigator.of(dialogContext).pop(true),
              child: const Text('ยกเลิกนัดหมาย'),
            ),
          ],
        );
      },
    );

    if (confirmed != true || !mounted) {
      return;
    }

    setState(() {
      _saving = true;
      _formError = null;
      _conflictMessage = null;
      _stale = false;
    });

    final AppointmentWriteOutcome outcome = await ref
        .read(appointmentsControllerProvider.notifier)
        .cancel(current);

    if (!mounted) {
      return;
    }

    _handleOutcome(outcome);
  }

  static String? _optional(String value) {
    final String trimmed = value.trim();

    return trimmed.isEmpty ? null : trimmed;
  }

  String _dateLabel() {
    final DateTime? startsAt = _startsAt;

    return startsAt == null
        ? 'เลือกวันที่'
        : formatThaiDate(startsAt.toIso8601String());
  }

  String _timeLabel() {
    final DateTime? startsAt = _startsAt;

    if (startsAt == null) {
      return 'เลือกเวลา';
    }

    final String hour = startsAt.hour.toString().padLeft(2, '0');
    final String minute = startsAt.minute.toString().padLeft(2, '0');

    return '$hour:$minute น.';
  }

  bool get _canCancel {
    final Appointment? current = _current;

    return current != null &&
        (current.status == 'planned' || current.status == 'confirmed');
  }

  @override
  Widget build(BuildContext context) {
    final bool editing = _current != null;

    return Padding(
      key: const Key('appointment-form-sheet'),
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: SingleChildScrollView(
        padding: const EdgeInsets.all(AppSpacing.lg),
        child: Form(
          key: _formKey,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              Row(
                children: <Widget>[
                  Expanded(
                    child: Text(
                      editing ? 'แก้ไขนัดหมาย' : 'นัดหมายใหม่',
                      style: AppType.headline,
                    ),
                  ),
                  IconButton(
                    key: const Key('appointment-form-close'),
                    tooltip: 'ปิด',
                    onPressed: _saving
                        ? null
                        : () {
                            Navigator.of(context).pop();
                          },
                    icon: const Icon(Icons.close),
                  ),
                ],
              ),
              const SizedBox(height: AppSpacing.md),
              TextFormField(
                key: const Key('appointment-title'),
                controller: _title,
                textInputAction: TextInputAction.next,
                decoration: const InputDecoration(
                  labelText: 'ชื่อนัดหมาย',
                  border: OutlineInputBorder(),
                  errorMaxLines: 3,
                ),
                onChanged: (String value) => _clearServerError('title'),
                validator: _validateTitle,
              ),
              const SizedBox(height: AppSpacing.lg),
              Text('วันและเวลา', style: AppType.sectionTitle),
              const SizedBox(height: AppSpacing.sm),
              InkWell(
                key: const Key('appointment-date'),
                onTap: _saving ? null : _pickDate,
                borderRadius: BorderRadius.circular(AppRadius.sm),
                child: InputDecorator(
                  decoration: const InputDecoration(
                    labelText: 'วันที่',
                    border: OutlineInputBorder(),
                  ),
                  child: Row(
                    children: <Widget>[
                      Expanded(child: Text(_dateLabel(), style: AppType.body)),
                      const Icon(
                        Icons.calendar_today_outlined,
                        color: AppColors.textSecondary,
                      ),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: AppSpacing.md),
              InkWell(
                key: const Key('appointment-time'),
                onTap: _saving ? null : _pickTime,
                borderRadius: BorderRadius.circular(AppRadius.sm),
                child: InputDecorator(
                  decoration: const InputDecoration(
                    labelText: 'เวลา',
                    border: OutlineInputBorder(),
                  ),
                  child: Row(
                    children: <Widget>[
                      Expanded(child: Text(_timeLabel(), style: AppType.body)),
                      const Icon(
                        Icons.schedule,
                        color: AppColors.textSecondary,
                      ),
                    ],
                  ),
                ),
              ),
              if (_startError != null) ...<Widget>[
                const SizedBox(height: AppSpacing.xs),
                Text(
                  _startError!,
                  key: const Key('appointment-start-error'),
                  style: AppType.caption.copyWith(color: AppColors.danger),
                ),
              ],
              const SizedBox(height: AppSpacing.lg),
              TextFormField(
                key: const Key('appointment-duration'),
                controller: _duration,
                keyboardType: TextInputType.number,
                textInputAction: TextInputAction.next,
                inputFormatters: <TextInputFormatter>[
                  FilteringTextInputFormatter.digitsOnly,
                ],
                decoration: const InputDecoration(
                  labelText: 'ระยะเวลา (นาที)',
                  border: OutlineInputBorder(),
                  errorMaxLines: 3,
                ),
                onChanged: (String value) =>
                    _clearServerError('duration_minutes'),
                validator: _validateDuration,
              ),
              const SizedBox(height: AppSpacing.md),
              TextFormField(
                key: const Key('appointment-location'),
                controller: _location,
                textInputAction: TextInputAction.next,
                decoration: const InputDecoration(
                  labelText: 'สถานที่',
                  border: OutlineInputBorder(),
                  errorMaxLines: 3,
                ),
                onChanged: (String value) => _clearServerError('location'),
                validator: (String? value) => _serverError('location'),
              ),
              const SizedBox(height: AppSpacing.md),
              TextFormField(
                key: const Key('appointment-clinician'),
                controller: _clinician,
                textInputAction: TextInputAction.next,
                decoration: const InputDecoration(
                  labelText: 'แพทย์ผู้ตรวจ',
                  border: OutlineInputBorder(),
                  errorMaxLines: 3,
                ),
                onChanged: (String value) => _clearServerError('provider_name'),
                validator: (String? value) => _serverError('provider_name'),
              ),
              const SizedBox(height: AppSpacing.md),
              TextFormField(
                key: const Key('appointment-notes'),
                controller: _notes,
                minLines: 2,
                maxLines: 4,
                textInputAction: TextInputAction.newline,
                decoration: const InputDecoration(
                  labelText: 'บันทึกเพิ่มเติม',
                  border: OutlineInputBorder(),
                  errorMaxLines: 3,
                ),
                onChanged: (String value) => _clearServerError('purpose'),
                validator: (String? value) => _serverError('purpose'),
              ),
              if (_stale) ...<Widget>[
                const SizedBox(height: AppSpacing.lg),
                _staleNotice(),
              ],
              if (_conflictMessage != null) ...<Widget>[
                const SizedBox(height: AppSpacing.lg),
                _notice(
                  key: const Key('appointment-conflict'),
                  background: AppColors.dangerSurface,
                  foreground: AppColors.danger,
                  message: _conflictMessage!,
                ),
              ],
              if (_formError != null) ...<Widget>[
                const SizedBox(height: AppSpacing.lg),
                _notice(
                  key: const Key('appointment-form-error'),
                  background: AppColors.dangerSurface,
                  foreground: AppColors.danger,
                  message: _formError!,
                ),
              ],
              const SizedBox(height: AppSpacing.lg),
              FilledButton(
                key: const Key('appointment-save'),
                onPressed: _saving ? null : _submit,
                style: FilledButton.styleFrom(
                  backgroundColor: AppColors.info,
                  foregroundColor: AppColors.textOnAccent,
                  minimumSize: const Size.fromHeight(AppSpacing.xxxl),
                  textStyle: AppType.button,
                ),
                child: Text(_saving ? 'กำลังบันทึก…' : 'บันทึก'),
              ),
              if (editing && _canCancel) ...<Widget>[
                const SizedBox(height: AppSpacing.sm),
                OutlinedButton(
                  key: const Key('appointment-cancel-action'),
                  onPressed: _saving ? null : _confirmCancel,
                  style: OutlinedButton.styleFrom(
                    foregroundColor: AppColors.danger,
                    minimumSize: const Size.fromHeight(AppSpacing.xxxl),
                    textStyle: AppType.button,
                  ),
                  child: const Text('ยกเลิกนัดหมาย'),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }

  Widget _staleNotice() {
    return Container(
      key: const Key('appointment-stale'),
      width: double.infinity,
      padding: const EdgeInsets.all(AppSpacing.md),
      decoration: BoxDecoration(
        color: AppColors.warningSurface,
        borderRadius: BorderRadius.circular(AppRadius.md),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Text(
            StaleAppointment.messageText,
            style: AppType.label.copyWith(color: AppColors.warning),
          ),
          const SizedBox(height: AppSpacing.xs),
          const Text(
            'ข้อมูลในแบบฟอร์มอาจเก่าแล้ว กรุณาโหลดข้อมูลล่าสุดก่อนบันทึก',
            style: AppType.caption,
          ),
          const SizedBox(height: AppSpacing.sm),
          OutlinedButton(
            key: const Key('appointment-reload'),
            onPressed: _saving ? null : _reload,
            child: const Text('โหลดใหม่'),
          ),
        ],
      ),
    );
  }

  static Widget _notice({
    required Key key,
    required Color background,
    required Color foreground,
    required String message,
  }) {
    return Container(
      key: key,
      width: double.infinity,
      padding: const EdgeInsets.all(AppSpacing.md),
      decoration: BoxDecoration(
        color: background,
        borderRadius: BorderRadius.circular(AppRadius.md),
      ),
      child: Text(message, style: AppType.label.copyWith(color: foreground)),
    );
  }
}
