import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:lucky_care/core/documents/document_models.dart';
import 'package:lucky_care/core/documents/documents_repository.dart';
import 'package:lucky_care/core/network/api_client.dart';
import 'package:lucky_care/core/network/api_failure.dart';
import 'package:lucky_care/core/workspace/current_workspace.dart';

/// Records the multipart request instead of letting Dio reach the network.
class _RecordingApiClient extends ApiClient {
  _RecordingApiClient({this.response, this.failure}) : super(dio: Dio());

  Map<String, dynamic>? response;
  ApiFailure? failure;

  String? path;
  Object? data;
  Map<String, String>? headers;
  ProgressCallback? onSendProgress;

  @override
  Future<Map<String, dynamic>> post(
    String path, {
    Object? data,
    Map<String, String>? headers,
    ProgressCallback? onSendProgress,
  }) async {
    this.path = path;
    this.data = data;
    this.headers = headers;
    this.onSendProgress = onSendProgress;

    final ApiFailure? failure = this.failure;

    if (failure != null) {
      throw failure;
    }

    return response ?? const <String, dynamic>{};
  }
}

const WorkspaceContext _workspace = WorkspaceContext(workspaceId: 'ws-1');

void main() {
  group('J-T01 document upload', () {
    test('posts the bytes as multipart field `file` with kind photo', () async {
      final Uint8List bytes = Uint8List.fromList(<int>[1, 2, 3, 4, 5, 6, 7, 8]);
      final _RecordingApiClient api = _RecordingApiClient(
        response: <String, dynamic>{
          'data': <String, dynamic>{
            'id': 'document-1',
            'kind': 'photo',
            'mime_type': 'image/jpeg',
            'size_bytes': 8,
            'status': 'available',
          },
        },
      );
      final DocumentsRepository repository = DocumentsRepository(api);

      final MedicalDocument document = await repository.upload(
        context: _workspace,
        profileId: 'profile-1',
        bytes: bytes,
        filename: 'evidence-1.jpg',
        mimeType: 'image/jpeg',
      );

      expect(document.id, 'document-1');
      expect(api.path, '/workspaces/ws-1/profiles/profile-1/documents');
      expect(api.data, isA<FormData>());

      final FormData form = api.data! as FormData;
      final MapEntry<String, MultipartFile> file = form.files.single;

      expect(form.files, hasLength(1));
      expect(file.key, 'file');
      expect(file.value.filename, 'evidence-1.jpg');
      expect(file.value.length, bytes.length);
      expect(file.value.contentType?.mimeType, 'image/jpeg');
      expect(
        form.fields.any(
          (MapEntry<String, String> field) =>
              field.key == 'kind' && field.value == 'photo',
        ),
        isTrue,
      );
    });

    test('reports upload progress as a percentage', () async {
      final _RecordingApiClient api = _RecordingApiClient(
        response: <String, dynamic>{
          'data': <String, dynamic>{'id': 'document-1'},
        },
      );
      final List<int> progress = <int>[];

      await DocumentsRepository(api).upload(
        context: _workspace,
        profileId: 'profile-1',
        bytes: Uint8List.fromList(<int>[1, 2, 3, 4]),
        filename: 'evidence-1.jpg',
        mimeType: 'image/jpeg',
        onProgress: progress.add,
      );

      api.onSendProgress?.call(1, 4);
      api.onSendProgress?.call(4, 4);

      expect(progress, <int>[25, 100]);
    });
  });

  group('upload failure mapping', () {
    Future<DocumentUploadException> failureFor(ApiFailure failure) async {
      final _RecordingApiClient api = _RecordingApiClient(failure: failure);

      try {
        await DocumentsRepository(api).upload(
          context: _workspace,
          profileId: 'profile-1',
          bytes: Uint8List.fromList(<int>[1, 2, 3]),
          filename: 'evidence-1.jpg',
          mimeType: 'image/jpeg',
        );
      } on DocumentUploadException catch (error) {
        return error;
      }

      throw StateError('upload should have failed');
    }

    test('J-T02 a 422 keeps the server sentence from errors.file', () async {
      final DocumentUploadException error = await failureFor(
        const ApiFailure(
          status: 422,
          code: 'validation',
          message: 'ข้อมูลไม่ถูกต้อง',
          fieldErrors: <String, String>{
            'file': 'ไฟล์ต้องมีขนาดไม่เกิน 10485760 ไบต์',
          },
        ),
      );

      expect(error.status, 422);
      expect(error.message, 'ไฟล์ต้องมีขนาดไม่เกิน 10485760 ไบต์');
    });

    test('J-T03 a 503 scan_unavailable becomes the scanner sentence', () async {
      final DocumentUploadException error = await failureFor(
        const ApiFailure(
          status: 503,
          code: 'scan_unavailable',
          message: 'ไม่สามารถตรวจสอบไฟล์ได้ในขณะนี้',
        ),
      );

      expect(error.status, 503);
      expect(error.message, DocumentUploadException.scanUnavailableMessage);
      expect(error.message, 'ระบบตรวจไวรัสไม่พร้อมใช้งาน จึงยังอัปโหลดไม่ได้');
    });
  });

  group('signed URL', () {
    test('requests the url endpoint only when called', () async {
      final _RecordingApiClient api = _RecordingApiClient(
        response: <String, dynamic>{
          'data': <String, dynamic>{
            'url': 'https://files.example/document-1',
            'expires_at': '2026-10-24T10:00:00Z',
          },
        },
      );

      expect(api.path, isNull);

      final DocumentSignedUrl signed = await DocumentsRepository(api).signedUrl(
        context: _workspace,
        profileId: 'profile-1',
        documentId: 'document-1',
      );

      expect(
        api.path,
        '/workspaces/ws-1/profiles/profile-1/documents/document-1/url',
      );
      expect(signed.url, 'https://files.example/document-1');
      expect(signed.expiresAt, '2026-10-24T10:00:00Z');
    });
  });
}
