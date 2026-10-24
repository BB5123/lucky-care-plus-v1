import '../format/thai_date.dart';
import '../network/json_read.dart';

/// A local Thai dictionary for the closed notification template catalog.
///
/// The delivery payload deliberately carries no PHI (`.ai/MOBILE_DESIGN.md`
/// §7), so the client must never invent a medicine, value or person. An
/// unknown key falls back to a generic sentence.
const Map<String, String> notificationCopyByTemplate = <String, String>{
  'task.assigned': 'มีงานดูแลใหม่ที่มอบหมายให้คุณ',
  'task.due_soon': 'มีงานดูแลใกล้ถึงกำหนด',
  'task.escalated': 'มีงานดูแลที่ต้องได้รับการช่วยเหลือ',
  'appt.reminder': 'มีการแจ้งเตือนนัดหมาย',
  'appt.confirmed': 'นัดหมายได้รับการยืนยันแล้ว',
  'bg.activated': 'มีการเปิดใช้งานโหมดฉุกเฉิน',
  'medication.due': 'ถึงเวลาบันทึกการทานยา',
};

const String notificationGenericCopy = 'คุณมีการแจ้งเตือนใหม่';

String notificationCopyFor(String templateKey) {
  return notificationCopyByTemplate[templateKey] ?? notificationGenericCopy;
}

String notificationChannelLabel(String channel) {
  return switch (channel) {
    'in_app' => 'ในแอป',
    'push' => 'พุช',
    'line' => 'LINE',
    _ => 'ช่องทางอื่น',
  };
}

String notificationStatusLabel(String status) {
  return switch (status) {
    'queued' => 'รอส่ง',
    'sent' => 'ส่งแล้ว',
    'failed' => 'ส่งไม่สำเร็จ',
    'skipped' => 'ข้ามการส่ง',
    _ => 'ไม่ทราบสถานะ',
  };
}

/// `5 นาทีที่แล้ว` / `2 ชั่วโมงที่แล้ว` / a Thai date for older rows.
String formatRelativeThaiTime(String? isoValue, {DateTime? now}) {
  final String? value = isoValue;

  if (value == null || value.isEmpty) {
    return '';
  }

  final DateTime? parsed = DateTime.tryParse(value);

  if (parsed == null) {
    return '';
  }

  final DateTime local = parsed.toLocal();
  final DateTime reference = (now ?? DateTime.now()).toLocal();
  final Duration elapsed = reference.difference(local);

  if (elapsed.isNegative || elapsed.inMinutes < 1) {
    return 'เมื่อสักครู่';
  }

  if (elapsed.inMinutes < 60) {
    return '${elapsed.inMinutes} นาทีที่แล้ว';
  }

  if (elapsed.inHours < 24) {
    return '${elapsed.inHours} ชั่วโมงที่แล้ว';
  }

  if (elapsed.inDays < 7) {
    return '${elapsed.inDays} วันที่แล้ว';
  }

  return formatThaiDate(value);
}

/// One row from `GET /me/notifications/deliveries`.
///
/// `params` is intentionally absent from the API resource: the client renders
/// [templateKey] through the local dictionary and has no detail to leak.
class NotificationDelivery {
  const NotificationDelivery({
    required this.id,
    required this.channel,
    required this.templateKey,
    required this.status,
    this.attempts = 0,
    this.scheduledFor,
    this.sentAt,
    this.readAt,
    this.errorCode,
    this.subjectType,
    this.subjectId,
    this.createdAt,
  });

  final String id;
  final String channel;
  final String templateKey;
  final String status;
  final int attempts;
  final String? scheduledFor;
  final String? sentAt;
  final String? readAt;
  final String? errorCode;
  final String? subjectType;
  final String? subjectId;
  final String? createdAt;

  bool get isUnread => (readAt ?? '').isEmpty;

  bool get isFailed => status == 'failed';

  String get channelLabel => notificationChannelLabel(channel);

  String get statusLabel => notificationStatusLabel(status);

  String get copy => notificationCopyFor(templateKey);

  String get relativeTime {
    final String? stamp = createdAt ?? sentAt ?? scheduledFor;
    return formatRelativeThaiTime(stamp);
  }

  DateTime? get createdAtDate {
    final String? value = createdAt ?? sentAt ?? scheduledFor;
    return value == null ? null : DateTime.tryParse(value);
  }

  NotificationDelivery copyWith({String? readAt}) {
    return NotificationDelivery(
      id: id,
      channel: channel,
      templateKey: templateKey,
      status: status,
      attempts: attempts,
      scheduledFor: scheduledFor,
      sentAt: sentAt,
      readAt: readAt ?? this.readAt,
      errorCode: errorCode,
      subjectType: subjectType,
      subjectId: subjectId,
      createdAt: createdAt,
    );
  }

  factory NotificationDelivery.fromJson(Map<String, dynamic> json) {
    return NotificationDelivery(
      id: readString(json['id']),
      channel: readString(json['channel']),
      templateKey: readString(json['template_key']),
      status: readString(json['status'], fallback: 'queued'),
      attempts: readInt(json['attempts']),
      scheduledFor: readNullableString(json['scheduled_for']),
      sentAt: readNullableString(json['sent_at']),
      readAt: readNullableString(json['read_at']),
      errorCode: readNullableString(json['error_code']),
      subjectType: readNullableString(json['subject_type']),
      subjectId: readNullableString(json['subject_id']),
      createdAt: readNullableString(json['created_at']),
    );
  }
}

/// Unread first, then newest first. Failed deliveries stay in the same list.
List<NotificationDelivery> orderNotifications(
  List<NotificationDelivery> deliveries,
) {
  final List<NotificationDelivery> ordered = List<NotificationDelivery>.of(
    deliveries,
  );

  ordered.sort((NotificationDelivery left, NotificationDelivery right) {
    if (left.isUnread != right.isUnread) {
      return left.isUnread ? -1 : 1;
    }

    final DateTime? leftAt = left.createdAtDate;
    final DateTime? rightAt = right.createdAtDate;

    if (leftAt == null && rightAt == null) {
      return left.id.compareTo(right.id);
    }

    if (leftAt == null) {
      return 1;
    }

    if (rightAt == null) {
      return -1;
    }

    return rightAt.compareTo(leftAt);
  });

  return ordered;
}

/// The three channels the server accepts. Missing flags fail closed.
class NotificationChannels {
  const NotificationChannels({
    this.inApp = true,
    this.push = true,
    this.line = false,
  });

  final bool inApp;
  final bool push;
  final bool line;

  bool valueFor(String channel) {
    return switch (channel) {
      'in_app' => inApp,
      'push' => push,
      'line' => line,
      _ => false,
    };
  }

  Map<String, bool> toJson() {
    return <String, bool>{'in_app': inApp, 'push': push, 'line': line};
  }

  @override
  bool operator ==(Object other) {
    return other is NotificationChannels &&
        other.inApp == inApp &&
        other.push == push &&
        other.line == line;
  }

  @override
  int get hashCode => Object.hash(inApp, push, line);
}

/// A quiet-hours window in `HH:MM` form, as the server stores it.
class QuietHours {
  const QuietHours({required this.start, required this.end});

  final String start;
  final String end;

  Map<String, String> toJson() {
    return <String, String>{'start': start, 'end': end};
  }

  @override
  bool operator ==(Object other) {
    return other is QuietHours && other.start == start && other.end == end;
  }

  @override
  int get hashCode => Object.hash(start, end);
}

/// The actor's own notification preferences.
class NotificationPreferences {
  const NotificationPreferences({
    required this.locale,
    required this.channels,
    required this.digest,
    this.quietHours,
    this.updatedAt,
  });

  final String locale;
  final NotificationChannels channels;
  final QuietHours? quietHours;
  final String digest;
  final String? updatedAt;

  String get localeLabel => locale == 'en' ? 'อังกฤษ' : 'ไทย';

  String get digestLabel => digest == 'daily' ? 'สรุปวันละครั้ง' : 'ทันที';

  /// Returns a patch that contains only the fields that actually changed.
  ///
  /// Channel changes are narrowed to the individual switch, so saving one
  /// toggle never rewrites the other two channels.
  NotificationPreferencesPatch diff(NotificationPreferences next) {
    final Map<String, bool> changedChannels = <String, bool>{};

    if (channels.inApp != next.channels.inApp) {
      changedChannels['in_app'] = next.channels.inApp;
    }

    if (channels.push != next.channels.push) {
      changedChannels['push'] = next.channels.push;
    }

    if (channels.line != next.channels.line) {
      changedChannels['line'] = next.channels.line;
    }

    final QuietHours? currentQuiet = quietHours;
    final QuietHours? nextQuiet = next.quietHours;
    final bool quietChanged = currentQuiet != nextQuiet;

    return NotificationPreferencesPatch(
      locale: locale == next.locale ? null : next.locale,
      channels: changedChannels.isEmpty ? null : changedChannels,
      quietHours: quietChanged ? QuietHoursChange(nextQuiet) : null,
      digest: digest == next.digest ? null : next.digest,
    );
  }

  NotificationPreferences copyWith({
    String? locale,
    NotificationChannels? channels,
    QuietHours? quietHours,
    String? digest,
    String? updatedAt,
  }) {
    return NotificationPreferences(
      locale: locale ?? this.locale,
      channels: channels ?? this.channels,
      quietHours: quietHours ?? this.quietHours,
      digest: digest ?? this.digest,
      updatedAt: updatedAt ?? this.updatedAt,
    );
  }

  factory NotificationPreferences.fromJson(Map<String, dynamic> json) {
    final Map<String, dynamic> channels = readMap(json['channels']);
    final Map<String, dynamic> quiet = readMap(json['quiet_hours']);
    final String? quietStart = readNullableString(quiet['start']);
    final String? quietEnd = readNullableString(quiet['end']);

    return NotificationPreferences(
      locale: readString(json['locale'], fallback: 'th'),
      channels: NotificationChannels(
        inApp: readBool(channels['in_app']),
        push: readBool(channels['push']),
        line: readBool(channels['line']),
      ),
      quietHours: quietStart == null || quietEnd == null
          ? null
          : QuietHours(start: quietStart, end: quietEnd),
      digest: readString(json['digest'], fallback: 'immediate'),
      updatedAt: readNullableString(json['updated_at']),
    );
  }
}

/// Distinguishes "quiet hours unchanged" from "quiet hours turned off".
class QuietHoursChange {
  const QuietHoursChange(this.value);

  final QuietHours? value;
}

/// The body of `PATCH /me/notification-preferences`; absent fields are not
/// sent at all.
class NotificationPreferencesPatch {
  const NotificationPreferencesPatch({
    this.locale,
    this.channels,
    this.quietHours,
    this.digest,
  });

  final String? locale;
  final Map<String, bool>? channels;
  final QuietHoursChange? quietHours;
  final String? digest;

  bool get isEmpty =>
      locale == null && channels == null && quietHours == null && digest == null;

  Map<String, dynamic> toJson() {
    final Map<String, dynamic> json = <String, dynamic>{};
    final String? nextLocale = locale;
    final Map<String, bool>? nextChannels = channels;
    final QuietHoursChange? nextQuiet = quietHours;
    final String? nextDigest = digest;

    if (nextLocale != null) {
      json['locale'] = nextLocale;
    }

    if (nextChannels != null) {
      json['channels'] = nextChannels;
    }

    if (nextQuiet != null) {
      json['quiet_hours'] = nextQuiet.value?.toJson();
    }

    if (nextDigest != null) {
      json['digest'] = nextDigest;
    }

    return json;
  }
}
