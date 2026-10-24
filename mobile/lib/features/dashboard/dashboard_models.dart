import '../../core/network/json_read.dart';

/// Typed mirror of `DashboardAssembler` (backend).
///
/// The server is the authority on visibility and values. This layer only
/// narrows an untrusted JSON body; a missing or malformed zone becomes hidden,
/// never an exception (F-01 / F-09).
class DashboardPayload {
  const DashboardPayload({
    required this.family,
    required this.alerts,
    required this.tasksToday,
    required this.vitals,
    required this.nextAppointment,
  });

  final FamilyZone family;
  final AlertsZone alerts;
  final TasksTodayZone tasksToday;
  final VitalsZone vitals;
  final NextAppointmentZone nextAppointment;

  static const DashboardPayload empty = DashboardPayload(
    family: FamilyZone(visible: false),
    alerts: AlertsZone(visible: false),
    tasksToday: TasksTodayZone(visible: false),
    vitals: VitalsZone(visible: false),
    nextAppointment: NextAppointmentZone(visible: false),
  );

  factory DashboardPayload.fromJson(Map<String, dynamic> json) {
    return DashboardPayload(
      family: FamilyZone.fromJson(json['family']),
      alerts: AlertsZone.fromJson(json['alerts']),
      tasksToday: TasksTodayZone.fromJson(json['tasks_today']),
      vitals: VitalsZone.fromJson(json['vitals']),
      nextAppointment: NextAppointmentZone.fromJson(json['next_appointment']),
    );
  }
}

class FamilyMember {
  const FamilyMember({
    required this.personId,
    required this.profileId,
    required this.displayName,
    required this.ring,
    required this.status,
    required this.reason,
  });

  final String personId;
  final String profileId;
  final String displayName;
  final String ring;

  /// `green`, `yellow` or `red` — derived by the server from readable cards.
  final String status;
  final String reason;

  factory FamilyMember.fromJson(Map<String, dynamic> json) {
    return FamilyMember(
      personId: readString(json['person_id']),
      profileId: readString(json['profile_id']),
      displayName: readString(json['display_name']),
      ring: readString(json['ring'], fallback: 'support'),
      status: readString(json['status'], fallback: 'green'),
      reason: readString(json['reason'], fallback: 'stable'),
    );
  }
}

class FamilyZone {
  const FamilyZone({required this.visible, this.members = const <FamilyMember>[]});

  final bool visible;
  final List<FamilyMember> members;

  factory FamilyZone.fromJson(Object? value) {
    final Map<String, dynamic> json = readMap(value);

    if (!readBool(json['visible'])) {
      return const FamilyZone(visible: false);
    }

    final List<FamilyMember> members = readList(json['members'])
        .map((Object? entry) => FamilyMember.fromJson(readMap(entry)))
        .where((FamilyMember member) => member.personId.isNotEmpty)
        .toList(growable: false);

    return FamilyZone(visible: true, members: members);
  }
}

class AlertItem {
  const AlertItem({
    required this.level,
    required this.code,
    required this.messageKey,
    this.personId,
    this.taskId,
  });

  /// `critical`, `warning` or `info`.
  final String level;
  final String code;

  /// Translation key, never a free-text value (N-02).
  final String messageKey;
  final String? personId;
  final String? taskId;

  bool get isCritical => level == 'critical';

  factory AlertItem.fromJson(Map<String, dynamic> json) {
    return AlertItem(
      level: readString(json['level'], fallback: 'info'),
      code: readString(json['code']),
      messageKey: readString(json['message_key']),
      personId: readNullableString(json['person_id']),
      taskId: readNullableString(json['task_id']),
    );
  }
}

class AlertsZone {
  const AlertsZone({required this.visible, this.items = const <AlertItem>[]});

  final bool visible;
  final List<AlertItem> items;

  factory AlertsZone.fromJson(Object? value) {
    final Map<String, dynamic> json = readMap(value);

    if (!readBool(json['visible'])) {
      return const AlertsZone(visible: false);
    }

    final List<AlertItem> items = readList(json['items'])
        .map((Object? entry) => AlertItem.fromJson(readMap(entry)))
        .where((AlertItem item) => item.messageKey.isNotEmpty)
        .toList(growable: false);

    return AlertsZone(visible: true, items: items);
  }
}

class TaskCard {
  const TaskCard({
    required this.id,
    required this.title,
    required this.status,
    required this.priority,
    this.dueAt,
    this.profileDisplayName,
  });

  final String id;
  final String title;
  final String status;
  final String priority;
  final String? dueAt;
  final String? profileDisplayName;

  factory TaskCard.fromJson(Map<String, dynamic> json) {
    return TaskCard(
      id: readString(json['id']),
      title: readString(json['title']),
      status: readString(json['status'], fallback: 'open'),
      priority: readString(json['priority'], fallback: 'normal'),
      dueAt: readNullableString(json['due_at']),
      profileDisplayName: readNullableString(json['profile_display_name']),
    );
  }
}

class TasksTodayZone {
  const TasksTodayZone({
    required this.visible,
    this.total = 0,
    this.done = 0,
    this.overdue = 0,
    this.items = const <TaskCard>[],
  });

  final bool visible;
  final int total;
  final int done;
  final int overdue;
  final List<TaskCard> items;

  double get progress {
    if (total <= 0) {
      return 0;
    }

    return (done / total).clamp(0, 1).toDouble();
  }

  factory TasksTodayZone.fromJson(Object? value) {
    final Map<String, dynamic> json = readMap(value);

    if (!readBool(json['visible'])) {
      return const TasksTodayZone(visible: false);
    }

    return TasksTodayZone(
      visible: true,
      total: readInt(json['total']),
      done: readInt(json['done']),
      overdue: readInt(json['overdue']),
      items: readList(json['items'])
          .map((Object? entry) => TaskCard.fromJson(readMap(entry)))
          .where((TaskCard item) => item.id.isNotEmpty)
          .toList(growable: false),
    );
  }
}

class VitalCard {
  const VitalCard({
    required this.type,
    required this.status,
    this.value,
    this.secondaryValue,
    this.unit = '',
  });

  final String type;
  final String status;
  final double? value;
  final double? secondaryValue;
  final String unit;

  /// `150/95` for blood pressure, `72` for a single value.
  String get valueLabel {
    final double? primary = value;

    if (primary == null) {
      return '—';
    }

    final String first = _formatNumber(primary);
    final double? secondary = secondaryValue;

    if (secondary == null) {
      return first;
    }

    return '$first/${_formatNumber(secondary)}';
  }

  factory VitalCard.fromJson(Map<String, dynamic> json) {
    final Map<String, dynamic> latest = readMap(json['latest']);

    return VitalCard(
      type: readString(json['type']),
      status: readString(json['status'], fallback: 'normal'),
      value: readNullableDouble(latest['value']),
      secondaryValue: readNullableDouble(latest['secondary_value']),
      unit: readString(latest['unit']),
    );
  }

  static String _formatNumber(double value) {
    if (value == value.roundToDouble()) {
      return value.toInt().toString();
    }

    return value.toString();
  }
}

class VitalsZone {
  const VitalsZone({required this.visible, this.cards = const <VitalCard>[]});

  final bool visible;
  final List<VitalCard> cards;

  factory VitalsZone.fromJson(Object? value) {
    final Map<String, dynamic> json = readMap(value);

    if (!readBool(json['visible'])) {
      return const VitalsZone(visible: false);
    }

    return VitalsZone(
      visible: true,
      cards: readList(json['cards'])
          .map((Object? entry) => VitalCard.fromJson(readMap(entry)))
          .where((VitalCard card) => card.type.isNotEmpty)
          .toList(growable: false),
    );
  }
}

class NextAppointmentZone {
  const NextAppointmentZone({
    required this.visible,
    this.title,
    this.scheduledAt,
  });

  final bool visible;
  final String? title;
  final String? scheduledAt;

  bool get hasAppointment =>
      visible &&
      title != null &&
      title!.isNotEmpty &&
      scheduledAt != null &&
      scheduledAt!.isNotEmpty;

  factory NextAppointmentZone.fromJson(Object? value) {
    final Map<String, dynamic> json = readMap(value);

    if (!readBool(json['visible'])) {
      return const NextAppointmentZone(visible: false);
    }

    return NextAppointmentZone(
      visible: true,
      title: readNullableString(json['title']),
      scheduledAt: readNullableString(json['scheduled_at']),
    );
  }
}

/// What the dashboard screen needs once `/me` and the aggregate have loaded.
class DashboardData {
  const DashboardData({required this.payload, this.workspaceName});

  final DashboardPayload payload;

  /// `null` when the user belongs to no workspace yet.
  final String? workspaceName;

  bool get hasWorkspace => workspaceName != null;

  static const DashboardData absent = DashboardData(
    payload: DashboardPayload.empty,
  );
}
