import '../network/json_read.dart';

/// The default request window reported by the API when a proxy strips both the
/// problem body field and the `Retry-After` header (the same 24 hours the web
/// client assumes).
const int defaultExportRateLimitSeconds = 24 * 60 * 60;

const String rateLimitedMessage =
    'มีการขอสำเนาข้อมูลบ่อยเกินไป กรุณารอตามเวลาที่แจ้ง';
const String notPermittedMessage = 'สิทธิ์ของคุณยังไม่รวมการขอข้อมูล';
const String expiredMessage = 'สำเนาข้อมูลนี้หมดอายุแล้ว กรุณาขอใหม่';
const String notReadyMessage =
    'สำเนาข้อมูลยังจัดเตรียมไม่เสร็จ กรุณาลองใหม่ภายหลัง';
const String exportUnavailableMessage =
    'ไม่สามารถดำเนินการกับสำเนาข้อมูลได้ในขณะนี้';
const String exportRequestUnreadableMessage =
    'ไม่สามารถอ่านสถานะคำขอสำเนาข้อมูลได้';
const String exportStatusUnreadableMessage = 'ไม่สามารถอ่านสถานะสำเนาข้อมูลได้';
const String exportLinkUnreadableMessage =
    'ไม่สามารถเปิดลิงก์ดาวน์โหลดได้ในขณะนี้';
const String exportWorkspaceMissingMessage =
    'ไม่พบพื้นที่ทำงานสำหรับขอสำเนาข้อมูล';
const String exportProfileMissingMessage = 'ไม่พบโปรไฟล์สำหรับขอสำเนาข้อมูล';

/// A typed export problem.
///
/// The four named outcomes are the ones the API documents explicitly. A card
/// maps each one to a different piece of copy, so a caller must never reduce
/// them to one generic error (`.ai/MOBILE_DESIGN.md` §5/§9).
sealed class ExportFailure implements Exception {
  const ExportFailure(this.message);

  /// Safe Thai sentence for the user.
  final String message;

  @override
  String toString() => '$runtimeType($message)';
}

/// `429` / `export_rate_limited`: a request already exists inside the privacy
/// window. [retryAfterSeconds] is the remaining wait when the server sent it.
final class ExportRateLimited extends ExportFailure {
  const ExportRateLimited({
    this.retryAfterSeconds = defaultExportRateLimitSeconds,
  }) : super(rateLimitedMessage);

  final int retryAfterSeconds;
}

/// `403` / `export_not_permitted`: the actor may not ask for a copy. This is a
/// decision, not a fault, so the card explains it calmly.
final class ExportNotPermitted extends ExportFailure {
  const ExportNotPermitted() : super(notPermittedMessage);
}

/// `410` / `export_expired`: the copy (or its link) is no longer available.
final class ExportExpired extends ExportFailure {
  const ExportExpired() : super(expiredMessage);
}

/// `409` / `export_not_ready`: the build has not finished yet; keep polling.
final class ExportNotReady extends ExportFailure {
  const ExportNotReady() : super(notReadyMessage);
}

/// Transport, protocol, or any other failure with a safe Thai sentence.
final class ExportUnavailable extends ExportFailure {
  const ExportUnavailable([super.message = exportUnavailableMessage]);
}

/// `POST .../exports` returns only the job id and its lifecycle status.
class ExportRequestResult {
  const ExportRequestResult({required this.id, required this.status});

  final String id;
  final String status;

  static ExportRequestResult? parse(Object? raw) {
    final Map<String, dynamic> json = readMap(raw);
    final String id = readString(json['id']);
    final String status = readString(json['status']);

    if (id.isEmpty || status.isEmpty) {
      return null;
    }

    return ExportRequestResult(id: id, status: status);
  }
}

/// `GET .../exports/{id}`. Deliberately carries no object key and no URL: the
/// file is addressed only through a freshly minted, short-lived link.
class ExportSnapshot {
  const ExportSnapshot({
    required this.id,
    required this.status,
    this.expiresAt = '',
    this.byteSize,
  });

  final String id;
  final String status;
  final String expiresAt;
  final int? byteSize;

  static ExportSnapshot? parse(Object? raw) {
    final Map<String, dynamic> json = readMap(raw);
    final String id = readString(json['id']);
    final String status = readString(json['status']);

    if (id.isEmpty || status.isEmpty) {
      return null;
    }

    return ExportSnapshot(
      id: id,
      status: status,
      expiresAt: readString(json['expires_at']),
      byteSize: _readByteSize(json['byte_size']),
    );
  }
}

/// `POST .../exports/{id}/link`: a short-lived capability the caller must use
/// immediately.
class ExportLink {
  const ExportLink({required this.url, this.expiresAt = ''});

  final String url;
  final String expiresAt;

  static ExportLink? parse(Object? raw) {
    final Map<String, dynamic> json = readMap(raw);
    final String url = readString(json['url']);

    if (url.isEmpty) {
      return null;
    }

    return ExportLink(url: url, expiresAt: readString(json['expires_at']));
  }
}

int? _readByteSize(Object? value) {
  if (value is int) {
    return value < 0 ? null : value;
  }

  if (value is num) {
    final int size = value.toInt();

    return size < 0 ? null : size;
  }

  return null;
}
