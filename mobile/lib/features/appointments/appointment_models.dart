import '../../core/network/json_read.dart';

String appointmentStatusLabel(String status) {
  return switch (status) {
    'planned' => 'วางแผนไว้',
    'confirmed' => 'ยืนยันแล้ว',
    'attended' => 'เข้ารับบริการแล้ว',
    'rescheduled' => 'เลื่อนนัด',
    'cancelled' => 'ยกเลิก',
    'missed' => 'ไม่ได้ไป',
    'needs_followup' => 'ต้องติดตาม',
    _ => 'ไม่ทราบสถานะ',
  };
}

/// One appointment from `GET .../appointments`.
class Appointment {
  const Appointment({
    required this.id,
    required this.title,
    required this.status,
    this.providerName,
    this.facility,
    this.location,
    this.notes,
    this.scheduledAt,
    this.durationMinutes,
    this.version = 1,
  });

  final String id;
  final String title;
  final String status;
  final String? providerName;
  final String? facility;
  final String? location;
  final String? notes;
  final String? scheduledAt;
  final int? durationMinutes;

  /// Optimistic-locking token. Every write sends it as `If-Match`.
  final int version;

  String get statusLabel => appointmentStatusLabel(status);

  DateTime? get scheduledAtDate {
    final String? value = scheduledAt;

    return value == null ? null : DateTime.tryParse(value);
  }

  /// A future, still-actionable appointment is listed before past history.
  bool isUpcoming(DateTime now) {
    final DateTime? at = scheduledAtDate;

    if (at == null) {
      return false;
    }

    if (status == 'cancelled' || status == 'missed') {
      return false;
    }

    return !at.isBefore(now);
  }

  factory Appointment.fromJson(Map<String, dynamic> json) {
    final Object? duration = json['duration_minutes'];
    final Object? version = json['version'];

    return Appointment(
      id: readString(json['id']),
      title: readString(json['title']),
      status: readString(json['status'], fallback: 'planned'),
      providerName: readNullableString(json['provider_name']),
      facility: readNullableString(json['facility']),
      location: readNullableString(json['location']),
      notes: readNullableString(json['purpose']),
      scheduledAt: readNullableString(json['scheduled_at']),
      durationMinutes: duration is int ? duration : null,
      version: version is int ? version : 1,
    );
  }
}

/// Upcoming first (soonest at the top), then past history (most recent first).
List<Appointment> orderAppointments(
  List<Appointment> appointments,
  DateTime now,
) {
  final List<Appointment> upcoming = appointments
      .where((Appointment appointment) => appointment.isUpcoming(now))
      .toList(growable: false);
  final List<Appointment> history = appointments
      .where((Appointment appointment) => !appointment.isUpcoming(now))
      .toList(growable: false);

  upcoming.sort(_ascending);
  history.sort(_descending);

  return <Appointment>[...upcoming, ...history];
}

int _ascending(Appointment left, Appointment right) {
  final DateTime? leftAt = left.scheduledAtDate;
  final DateTime? rightAt = right.scheduledAtDate;

  if (leftAt == null && rightAt == null) {
    return left.title.compareTo(right.title);
  }

  if (leftAt == null) {
    return 1;
  }

  if (rightAt == null) {
    return -1;
  }

  return leftAt.compareTo(rightAt);
}

int _descending(Appointment left, Appointment right) {
  final DateTime? leftAt = left.scheduledAtDate;
  final DateTime? rightAt = right.scheduledAtDate;

  if (leftAt == null && rightAt == null) {
    return left.title.compareTo(right.title);
  }

  if (leftAt == null) {
    return 1;
  }

  if (rightAt == null) {
    return -1;
  }

  return rightAt.compareTo(leftAt);
}

/// What the appointments tab needs once the context and list resolve.
class AppointmentsData {
  AppointmentsData({
    required this.hasProfile,
    required List<Appointment> appointments,
    DateTime? now,
    this.canManage = false,
  }) : appointments = List<Appointment>.unmodifiable(
         orderAppointments(appointments, now ?? DateTime.now()),
       );

  const AppointmentsData._({
    required this.hasProfile,
    required this.appointments,
    required this.canManage,
  });

  final bool hasProfile;
  final List<Appointment> appointments;

  /// Whether this session may open the create/edit sheet. The server still
  /// owns `appointment.create` / `appointment.update` / `appointment.cancel`;
  /// the flag only decides whether the affordance is rendered (hide vs show,
  /// `.ai/MOBILE_DESIGN.md` §5).
  final bool canManage;

  bool get isEmpty => appointments.isEmpty;

  Appointment? byId(String id) {
    for (final Appointment appointment in appointments) {
      if (appointment.id == id) {
        return appointment;
      }
    }

    return null;
  }

  static const AppointmentsData noProfile = AppointmentsData._(
    hasProfile: false,
    appointments: <Appointment>[],
    canManage: false,
  );
}
