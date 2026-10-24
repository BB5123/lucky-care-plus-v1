import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../network/api_client.dart';
import '../network/api_failure.dart';
import '../network/network_providers.dart';
import '../workspace/current_workspace.dart';
import 'document_models.dart';

/// A renderable upload/link failure.
///
/// [message] is always a Thai sentence safe for the UI: a 422 keeps the
/// server's own `errors.file` sentence, a 503 `scan_unavailable` becomes the
/// product sentence (the server's own wording is more technical), and every
/// other failure keeps [ApiFailure]'s sanitized message. Raw status codes,
/// stack traces and payload dumps never reach a screen (N-02).
class DocumentUploadException implements Exception {
  const DocumentUploadException({
    required this.status,
    required this.code,
    required this.message,
  });

  /// The backend refuses uploads while no scanner is deployed (F-09).
  static const String scanUnavailableMessage =
      'ระบบตรวจไวรัสไม่พร้อมใช้งาน จึงยังอัปโหลดไม่ได้';

  static const String storageUnavailableMessage =
      'ไม่สามารถจัดเก็บไฟล์ได้ในขณะนี้';

  static const String invalidPayloadMessage =
      'ไม่สามารถอ่านข้อมูลไฟล์ที่อัปโหลดได้';

  /// HTTP status, or `null` when the bytes never left the device.
  final int? status;

  /// Problem code (`scan_unavailable`, `validation`, ...) or a local code.
  final String code;

  /// Thai sentence for the user.
  final String message;

  factory DocumentUploadException.fromFailure(ApiFailure failure) {
    if (failure.code == 'scan_unavailable') {
      return DocumentUploadException(
        status: failure.status,
        code: failure.code,
        message: scanUnavailableMessage,
      );
    }

    if (failure.code == 'storage_unavailable') {
      return DocumentUploadException(
        status: failure.status,
        code: failure.code,
        message: storageUnavailableMessage,
      );
    }

    // A 422 names the exact rule (size/type) under `errors.file`; that sentence
    // is already Thai on the server and is preferable to the generic detail.
    final String? fileMessage = failure.fieldErrors['file'];

    return DocumentUploadException(
      status: failure.status,
      code: failure.code,
      message: fileMessage ?? failure.message,
    );
  }

  @override
  String toString() => 'DocumentUploadException(status: $status, code: $code)';
}

/// Medical-document upload and on-demand signed links.
///
/// There is deliberately no `delete`: the baseline denies document deletion by
/// default, so the app must not offer it. Files are sent as
/// `multipart/form-data` with the field `file`; the server sniffs the actual
/// bytes and owns every validation decision.
///
/// The signed URL is never cached: the caller opens it immediately and it
/// expires on the server's TTL.
class DocumentsRepository {
  DocumentsRepository(this._api);

  final ApiClient _api;

  Future<MedicalDocument> upload({
    required WorkspaceContext context,
    required String profileId,
    required Uint8List bytes,
    required String filename,
    required String mimeType,
    void Function(int percent)? onProgress,
  }) async {
    final String path = _documentsPath(context, profileId);
    final FormData form = FormData.fromMap(<String, Object>{
      'file': MultipartFile.fromBytes(
        bytes,
        filename: filename,
        contentType: _mediaType(mimeType),
      ),
      'kind': DocumentKinds.photo,
    });

    try {
      final Map<String, dynamic> body = await _api.post(
        path,
        data: form,
        onSendProgress: onProgress == null
            ? null
            : (int sent, int total) {
                if (total <= 0) {
                  return;
                }

                onProgress((sent * 100 / total).round().clamp(0, 100));
              },
      );
      final MedicalDocument? document = MedicalDocument.parse(body['data']);

      if (document == null) {
        throw const DocumentUploadException(
          status: null,
          code: 'protocol',
          message: DocumentUploadException.invalidPayloadMessage,
        );
      }

      return document;
    } on ApiFailure catch (failure) {
      throw DocumentUploadException.fromFailure(failure);
    }
  }

  /// Issues a short-lived link. The caller must use it immediately; keeping it
  /// would extend a capability past its TTL.
  Future<DocumentSignedUrl> signedUrl({
    required WorkspaceContext context,
    required String profileId,
    required String documentId,
  }) async {
    final String path = '${_documentsPath(context, profileId)}/$documentId/url';
    final Map<String, dynamic> body = await _api.post(path);
    final DocumentSignedUrl? signed = DocumentSignedUrl.parse(body['data']);

    if (signed == null) {
      throw const DocumentUploadException(
        status: null,
        code: 'protocol',
        message: 'ไม่สามารถเปิดไฟล์ได้ในขณะนี้',
      );
    }

    return signed;
  }

  String _documentsPath(WorkspaceContext context, String profileId) {
    final String? workspaceId = context.workspaceId;

    if (workspaceId == null || workspaceId.isEmpty) {
      throw const DocumentUploadException(
        status: null,
        code: 'out_of_scope',
        message: 'ไม่พบพื้นที่ทำงานสำหรับอัปโหลดไฟล์',
      );
    }

    if (profileId.isEmpty) {
      throw const DocumentUploadException(
        status: null,
        code: 'out_of_scope',
        message: 'ไม่พบโปรไฟล์สำหรับอัปโหลดไฟล์',
      );
    }

    return '/workspaces/$workspaceId/profiles/$profileId/documents';
  }

  /// Unknown types are sent as an opaque type; the server trusts only the
  /// sniffed bytes, so a bad declaration cannot widen what it accepts.
  DioMediaType _mediaType(String mimeType) {
    try {
      return DioMediaType.parse(mimeType);
    } on FormatException {
      return DioMediaType('application', 'octet-stream');
    }
  }
}

final Provider<DocumentsRepository> documentsRepositoryProvider =
    Provider<DocumentsRepository>(
      (Ref ref) => DocumentsRepository(ref.watch(apiClientProvider)),
    );
