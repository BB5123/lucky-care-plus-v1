import '../../core/format/thai_date.dart';
import 'dashboard_models.dart';

/// What the single-focus card points at.
enum ElderFocusKind { task, appointment, record }

/// The one thing the dashboard asks an elder-mode user to do now.
class ElderFocusAction {
  const ElderFocusAction({
    required this.kind,
    required this.title,
    required this.detail,
    required this.label,
    required this.route,
  });

  final ElderFocusKind kind;
  final String title;
  final String detail;

  /// The full-width primary button label.
  final String label;

  /// The tab the primary action opens.
  final String route;
}

/// Urgency order from `.ai/MOBILE_DESIGN.md` §6: the next due task, then the
/// next appointment, then recording a reading. The server remains the
/// authority on which zones are visible; a hidden zone is never considered.
ElderFocusAction selectElderFocusAction(DashboardPayload payload) {
  final TaskCard? task = _nextDueTask(payload.tasksToday);

  if (task != null) {
    final String dueLabel = _dueLabel(task.dueAt);

    return ElderFocusAction(
      kind: ElderFocusKind.task,
      title: task.title.isEmpty ? 'งานดูแล' : task.title,
      detail: dueLabel,
      label: 'เปิดงานดูแล',
      route: '/tasks',
    );
  }

  final NextAppointmentZone appointment = payload.nextAppointment;

  if (appointment.hasAppointment) {
    return ElderFocusAction(
      kind: ElderFocusKind.appointment,
      title: 'นัดหมาย: ${appointment.title}',
      detail: _dueLabel(appointment.scheduledAt),
      label: 'ดูนัดหมาย',
      route: '/appointments',
    );
  }

  return const ElderFocusAction(
    kind: ElderFocusKind.record,
    title: 'วันนี้ยังไม่มีสิ่งที่ต้องทำ',
    detail: 'บันทึกค่าสุขภาพไว้ให้ครอบครัวดู',
    label: 'บันทึกค่า',
    route: '/health',
  );
}

/// One line of family status, replacing the member chips in elder mode.
String? elderFamilyStatusLine(FamilyZone family) {
  if (!family.visible || family.members.isEmpty) {
    return null;
  }

  final List<FamilyMember> critical = family.members
      .where((FamilyMember member) => member.status == 'red')
      .toList(growable: false);

  if (critical.isNotEmpty) {
    return critical.length == 1
        ? '${_memberName(critical.first)} · ต้องดูแลทันที'
        : 'มี ${critical.length} คนที่ต้องดูแลทันที';
  }

  final List<FamilyMember> warning = family.members
      .where((FamilyMember member) => member.status == 'yellow')
      .toList(growable: false);

  if (warning.isNotEmpty) {
    return warning.length == 1
        ? '${_memberName(warning.first)} · มีงานค้าง'
        : 'มี ${warning.length} คนที่มีงานค้าง';
  }

  return family.members.length == 1
      ? '${_memberName(family.members.first)} · ปกติ'
      : 'ครอบครัวปกติ ${family.members.length} คน';
}

TaskCard? _nextDueTask(TasksTodayZone zone) {
  if (!zone.visible) {
    return null;
  }

  final List<TaskCard> open = zone.items
      .where(
        (TaskCard task) =>
            task.status != 'completed' && task.status != 'cancelled',
      )
      .toList(growable: false);

  if (open.isEmpty) {
    return null;
  }

  final List<TaskCard> sorted = List<TaskCard>.of(open)
    ..sort((TaskCard left, TaskCard right) {
      final DateTime? leftDue = _parseDate(left.dueAt);
      final DateTime? rightDue = _parseDate(right.dueAt);

      if (leftDue == null && rightDue == null) {
        return left.title.compareTo(right.title);
      }
      if (leftDue == null) {
        return 1;
      }
      if (rightDue == null) {
        return -1;
      }

      return leftDue.compareTo(rightDue);
    });

  return sorted.first;
}

String _dueLabel(String? isoValue) {
  if (isoValue == null) {
    return 'ไม่ระบุเวลา';
  }

  final String formatted = formatThaiDateTime(isoValue);

  return formatted.isEmpty ? 'ไม่ระบุเวลา' : 'ครบกำหนด $formatted';
}

DateTime? _parseDate(String? isoValue) {
  if (isoValue == null) {
    return null;
  }

  return DateTime.tryParse(isoValue);
}

String _memberName(FamilyMember member) {
  return member.displayName.isEmpty ? 'สมาชิกในครอบครัว' : member.displayName;
}
