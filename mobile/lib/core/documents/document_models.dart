import '../network/json_read.dart';

/// The document kinds the upload endpoint accepts (`config/documents.php`).
///
/// Photo evidence only ever sends [photo]; the other values exist so the
/// parser does not freeze an unknown server kind into an exception.
abstract final class DocumentKinds {
  static const String labResult = 'lab_result';
  static const String prescription = 'prescription';
  static const String dischargeSummary = 'discharge_summary';
  static const String photo = 'photo';
  static const String other = 'other';

  static const List<String> all = <String>[
    labResult,
    prescription,
    dischargeSummary,
    photo,
    other,
  ];
}

/// One medical document, addressed only by its opaque id.
///
/// There is deliberately no storage path, disk or signed URL here: the server
/// never exposes where the bytes live (ABD-014, N-02) and a short-lived link is
/// issued on demand by `DocumentsRepository.signedUrl()`.
///
/// The upload acknowledgement omits `original_name` on purpose: upload
/// permission is not read permission and a filename can contain a person's
/// name, so the client never assumes it is present.
class MedicalDocument {
  const MedicalDocument({
    required this.id,
    this.kind = DocumentKinds.other,
    this.mimeType = 'application/octet-stream',
    this.sizeBytes,
    this.status = 'available',
  });

  final String id;
  final String kind;
  final String mimeType;
  final int? sizeBytes;
  final String status;

  factory MedicalDocument.fromJson(Map<String, dynamic> json) {
    return MedicalDocument(
      id: readString(json['id']),
      kind: _readKind(json['kind']),
      mimeType: _readMimeType(json['mime_type']),
      sizeBytes: _readSize(json['size_bytes']),
      status: readString(json['status'], fallback: 'available'),
    );
  }

  /// A payload without an id is not a document; every other field degrades to
  /// a safe default instead of throwing (F-09).
  static MedicalDocument? parse(Object? value) {
    if (value is! Map) {
      return null;
    }

    final MedicalDocument document = MedicalDocument.fromJson(readMap(value));

    return document.id.isEmpty ? null : document;
  }

  static String _readKind(Object? value) {
    final String kind = readString(value, fallback: DocumentKinds.other);

    return DocumentKinds.all.contains(kind) ? kind : DocumentKinds.other;
  }

  static String _readMimeType(Object? value) {
    final String mimeType = readString(value);

    return mimeType.isEmpty ? 'application/octet-stream' : mimeType;
  }

  static int? _readSize(Object? value) {
    if (value is int) {
      return value;
    }

    if (value is num) {
      return value.toInt();
    }

    if (value is String) {
      return int.tryParse(value);
    }

    return null;
  }
}

/// A short-lived capability from `POST .../documents/{id}/url`.
///
/// The URL is never cached by the app: the caller opens it immediately and the
/// server expires it (baseline cap: 15 minutes).
class DocumentSignedUrl {
  const DocumentSignedUrl({required this.url, this.expiresAt});

  final String url;
  final String? expiresAt;

  static DocumentSignedUrl? parse(Object? value) {
    if (value is! Map) {
      return null;
    }

    final Map<String, dynamic> json = readMap(value);
    final String url = readString(json['url']);

    if (url.isEmpty) {
      return null;
    }

    return DocumentSignedUrl(
      url: url,
      expiresAt: readNullableString(json['expires_at']),
    );
  }
}
