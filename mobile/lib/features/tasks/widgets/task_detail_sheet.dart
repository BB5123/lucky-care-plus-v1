import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/documents/document_models.dart';
import '../../../core/documents/documents_repository.dart';
import '../../../core/media/photo_source.dart';
import '../../../core/network/api_failure.dart';
import '../../../core/workspace/current_workspace.dart';
import '../../../design_system/status_chip.dart';
import '../../../design_system/tokens.dart';
import '../task_models.dart';
import '../tasks_controller.dart';
import '../tasks_repository.dart';

/// Task detail and the actions the tier allows.
///
/// The server owns every transition; this sheet only chooses which buttons to
/// show per tier and renders the 409/412 answer it gets back. A clinical task
/// whose evidence policy requires a photo gains the attach flow: pick → upload
/// to the private document store → create the `photo` evidence row → refresh.
/// Nothing is attached when the upload fails, so a document id can never be
/// referenced before its bytes exist.
class TaskDetailSheet extends ConsumerStatefulWidget {
  const TaskDetailSheet({super.key, required this.task});

  final CareTask task;

  static Future<void> show(BuildContext context, CareTask task) {
    return showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      backgroundColor: AppColors.surfaceRaised,
      builder: (BuildContext context) => TaskDetailSheet(task: task),
    );
  }

  @override
  ConsumerState<TaskDetailSheet> createState() => _TaskDetailSheetState();
}

class _TaskDetailSheetState extends ConsumerState<TaskDetailSheet> {
  String? _error;
  bool _conflict = false;
  bool _busy = false;

  bool _hasPhotoEvidence = false;
  String? _attachedDocumentId;
  String? _attachedFilename;
  bool _uploading = false;
  int? _uploadProgress;
  String? _photoError;
  bool _openingFile = false;

  @override
  void initState() {
    super.initState();

    // Evidence is remembered for the life of the tasks controller, so closing
    // and reopening the sheet does not forget an attachment made in this
    // session. A fresh launch learns the truth from the server: the complete
    // transition is refused until a photo row exists (fail closed).
    final String? documentId = ref
        .read(tasksControllerProvider.notifier)
        .photoDocumentId(widget.task.id);

    _hasPhotoEvidence = documentId != null;
    _attachedDocumentId = documentId;
  }

  Future<void> _run(TaskAction action, {String? outcome}) async {
    setState(() {
      _busy = true;
      _error = null;
      _conflict = false;
      _photoError = null;
    });

    try {
      await ref
          .read(tasksControllerProvider.notifier)
          .run(widget.task, action, outcome: outcome);

      if (!mounted) {
        return;
      }

      Navigator.of(context).pop();
    } on ApiFailure catch (failure) {
      if (!mounted) {
        return;
      }

      setState(() {
        _busy = false;

        if (failure.status == 412) {
          _conflict = true;
          _error = null;
        } else {
          _conflict = false;
          _error = failure.message;
        }
      });
    } on Object {
      if (!mounted) {
        return;
      }

      setState(() {
        _busy = false;
        _conflict = false;
        _error = ApiFailure.genericMessage;
      });
    }
  }

  Future<void> _reload() async {
    setState(() {
      _busy = true;
      _conflict = false;
      _error = null;
    });

    await ref.read(tasksControllerProvider.notifier).refresh();

    if (!mounted) {
      return;
    }

    Navigator.of(context).pop();
  }

  Future<void> _attachPhoto(PhotoOrigin origin) async {
    if (_busy || _uploading) {
      return;
    }

    setState(() {
      _photoError = null;
      _error = null;
      _conflict = false;
    });

    PickedPhoto? picked;

    try {
      picked = await ref.read(photoSourceProvider).pick(origin);
    } on Object {
      if (!mounted) {
        return;
      }

      setState(() {
        _photoError = 'ไม่สามารถเปิดกล้องหรือคลังภาพได้ กรุณาลองใหม่';
      });

      return;
    }

    if (picked == null || !mounted) {
      return;
    }

    await _uploadPhoto(picked);
  }

  /// Upload first, then create the evidence row. The evidence call is only
  /// reachable after a 2xx upload, so a 422/503 leaves no row behind.
  Future<void> _uploadPhoto(PickedPhoto photo) async {
    setState(() {
      _uploading = true;
      _uploadProgress = 0;
      _photoError = null;
    });

    try {
      final WorkspaceContext workspace = await ref.read(
        currentWorkspaceProvider.future,
      );
      final String profileId = _profileId(workspace);
      final MedicalDocument document = await ref
          .read(documentsRepositoryProvider)
          .upload(
            context: workspace,
            profileId: profileId,
            bytes: photo.bytes,
            filename: photo.filename,
            mimeType: photo.mimeType,
            onProgress: (int percent) {
              if (mounted) {
                setState(() => _uploadProgress = percent);
              }
            },
          );

      if (!mounted) {
        return;
      }

      setState(() => _uploadProgress = 100);

      final TaskEvidence evidence = await ref
          .read(tasksRepositoryProvider)
          .addPhotoEvidence(
            context: workspace,
            taskId: widget.task.id,
            documentId: document.id,
            version: widget.task.version,
          );

      if (!mounted) {
        return;
      }

      final String documentId = (evidence.documentId ?? '').isEmpty
          ? document.id
          : evidence.documentId!;

      ref
          .read(tasksControllerProvider.notifier)
          .markPhotoEvidence(widget.task.id, documentId);

      setState(() {
        _hasPhotoEvidence = true;
        _attachedDocumentId = documentId;
        _attachedFilename = photo.filename;
      });

      // Re-read the list so the task's version and status are current before
      // the complete action is offered.
      await ref.read(tasksControllerProvider.notifier).refresh();
    } on DocumentUploadException catch (failure) {
      if (mounted) {
        setState(() => _photoError = _uploadMessage(failure));
      }
    } on ApiFailure catch (failure) {
      if (!mounted) {
        return;
      }

      setState(() {
        if (failure.status == 412) {
          _conflict = true;
        } else if (failure.code == 'scan_unavailable') {
          _photoError = DocumentUploadException.scanUnavailableMessage;
        } else {
          _photoError = failure.message;
        }
      });
    } on Object {
      if (mounted) {
        setState(() => _photoError = ApiFailure.genericMessage);
      }
    } finally {
      if (mounted) {
        setState(() {
          _uploading = false;
          _uploadProgress = null;
        });
      }
    }
  }

  /// Requests a fresh signed link and shows it in a one-shot dialog.
  ///
  /// The URL is a short-lived capability: it is never written to state, never
  /// cached, and disappears with the dialog.
  Future<void> _openAttachedFile() async {
    final String? documentId = _attachedDocumentId;

    if (documentId == null || _openingFile) {
      return;
    }

    setState(() {
      _openingFile = true;
      _photoError = null;
    });

    try {
      final WorkspaceContext workspace = await ref.read(
        currentWorkspaceProvider.future,
      );
      final DocumentSignedUrl signed = await ref
          .read(documentsRepositoryProvider)
          .signedUrl(
            context: workspace,
            profileId: _profileId(workspace),
            documentId: documentId,
          );

      if (!mounted) {
        return;
      }

      await showDialog<void>(
        context: context,
        builder: (BuildContext dialogContext) {
          return AlertDialog(
            title: const Text('ไฟล์หลักฐาน'),
            content: SelectableText(signed.url),
            actions: <Widget>[
              TextButton(
                key: const Key('task-copy-file-link'),
                onPressed: () async {
                  await Clipboard.setData(ClipboardData(text: signed.url));

                  if (dialogContext.mounted) {
                    Navigator.of(dialogContext).pop();
                  }
                },
                child: const Text('คัดลอกลิงก์'),
              ),
              TextButton(
                key: const Key('task-close-file-dialog'),
                onPressed: () => Navigator.of(dialogContext).pop(),
                child: const Text('ปิด'),
              ),
            ],
          );
        },
      );
    } on DocumentUploadException catch (failure) {
      if (mounted) {
        setState(() => _photoError = failure.message);
      }
    } on ApiFailure catch (failure) {
      if (mounted) {
        setState(() => _photoError = failure.message);
      }
    } on Object {
      if (mounted) {
        setState(() => _photoError = 'ไม่สามารถเปิดไฟล์ได้ในขณะนี้');
      }
    } finally {
      if (mounted) {
        setState(() => _openingFile = false);
      }
    }
  }

  String _profileId(WorkspaceContext workspace) {
    if (widget.task.profileId.isNotEmpty) {
      return widget.task.profileId;
    }

    return workspace.profileId ?? '';
  }

  /// The scanner outage gets the product sentence even if a lower layer
  /// surfaced the server's more technical wording: retrying with a different
  /// file cannot help while no scanner is deployed (F-09).
  String _uploadMessage(DocumentUploadException failure) {
    if (failure.code == 'scan_unavailable') {
      return DocumentUploadException.scanUnavailableMessage;
    }

    return failure.message;
  }

  /// A photo completes a `photo` policy. `both` also needs `value` evidence,
  /// which this sheet cannot create, so it stays fail-closed.
  bool _photoPolicySatisfied(CareTask task) {
    return _hasPhotoEvidence && task.evidencePolicy == 'photo';
  }

  @override
  Widget build(BuildContext context) {
    final CareTask task = widget.task;
    final String dueLabel = task.dueLabel;

    return SingleChildScrollView(
      padding: const EdgeInsets.all(AppSpacing.lg),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Row(
            children: <Widget>[
              const Expanded(
                child: Text('รายละเอียดงาน', style: AppType.headline),
              ),
              IconButton(
                tooltip: 'ปิด',
                onPressed: _busy || _uploading
                    ? null
                    : () {
                        Navigator.of(context).pop();
                      },
                icon: const Icon(Icons.close),
              ),
            ],
          ),
          const SizedBox(height: AppSpacing.md),
          Text(task.title, style: AppType.title),
          const SizedBox(height: AppSpacing.sm),
          Wrap(
            spacing: AppSpacing.sm,
            runSpacing: AppSpacing.sm,
            children: <Widget>[
              StatusChip(label: task.tierLabel),
              StatusChip(
                label: taskStatusLabel(task.status),
                tone: AppChipTone.info,
              ),
            ],
          ),
          if (dueLabel.isNotEmpty) ...<Widget>[
            const SizedBox(height: AppSpacing.md),
            Text(dueLabel, style: AppType.bodySecondary),
          ],
          const SizedBox(height: AppSpacing.lg),
          ..._actions(task),
          if (_photoError != null) ...<Widget>[
            const SizedBox(height: AppSpacing.md),
            _Notice(
              key: const Key('task-photo-error'),
              background: AppColors.dangerSurface,
              foreground: AppColors.danger,
              message: _photoError!,
            ),
          ],
          if (_error != null) ...<Widget>[
            const SizedBox(height: AppSpacing.md),
            _Notice(
              key: const Key('task-action-error'),
              background: AppColors.dangerSurface,
              foreground: AppColors.danger,
              message: _error!,
            ),
          ],
          if (_conflict) ...<Widget>[
            const SizedBox(height: AppSpacing.md),
            Container(
              key: const Key('task-conflict'),
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
                    'ข้อมูลถูกแก้ไขโดยคนอื่น',
                    style: AppType.label.copyWith(color: AppColors.warning),
                  ),
                  const SizedBox(height: AppSpacing.sm),
                  OutlinedButton(
                    key: const Key('task-reload-button'),
                    onPressed: _busy ? null : _reload,
                    child: const Text('โหลดใหม่'),
                  ),
                ],
              ),
            ),
          ],
        ],
      ),
    );
  }

  List<Widget> _actions(CareTask task) {
    switch (task.tier) {
      case 'routine':
        return <Widget>[
          FilledButton(
            key: const Key('task-complete-done'),
            onPressed: _busy
                ? null
                : () => _run(TaskAction.complete, outcome: 'done'),
            style: _primaryButtonStyle(),
            child: const Text('ทำเสร็จแล้ว'),
          ),
          const SizedBox(height: AppSpacing.sm),
          OutlinedButton(
            key: const Key('task-skip-button'),
            onPressed: _busy
                ? null
                : () => _run(TaskAction.complete, outcome: 'skipped'),
            child: const Text('ข้ามงาน'),
          ),
        ];
      case 'delegated':
        return <Widget>[
          OutlinedButton(
            key: const Key('task-accept-button'),
            onPressed: _busy ? null : () => _run(TaskAction.accept),
            child: const Text('รับงาน'),
          ),
          const SizedBox(height: AppSpacing.sm),
          OutlinedButton(
            key: const Key('task-start-button'),
            onPressed: _busy ? null : () => _run(TaskAction.start),
            child: const Text('เริ่มทำ'),
          ),
          const SizedBox(height: AppSpacing.sm),
          FilledButton(
            key: const Key('task-complete-done'),
            onPressed: _busy
                ? null
                : () => _run(TaskAction.complete, outcome: 'done'),
            style: _primaryButtonStyle(),
            child: const Text('ทำเสร็จแล้ว'),
          ),
        ];
      case 'clinical':
        // Only a clinical task whose policy needs a photo gains the attach
        // flow. `value` evidence is not part of this slice, so a `both` task
        // stays gated after its photo (the server would still reject it), and
        // the explanation remains instead of pretending the task is ready.
        final bool canComplete =
            !_busy && !_uploading && _photoPolicySatisfied(task);

        return <Widget>[
          if (task.requiresPhotoEvidence) ...<Widget>[
            _photoSection(),
            const SizedBox(height: AppSpacing.lg),
          ],
          FilledButton(
            key: const Key('task-complete-button'),
            onPressed: canComplete
                ? () => _run(TaskAction.complete, outcome: 'done')
                : null,
            style: _primaryButtonStyle(),
            child: const Text('ทำเสร็จแล้ว'),
          ),
          if (!canComplete && !_uploading) ...<Widget>[
            const SizedBox(height: AppSpacing.sm),
            Text(
              'ต้องแนบหลักฐานก่อน',
              key: const Key('task-evidence-reason'),
              style: AppType.label.copyWith(color: AppColors.warning),
            ),
          ],
        ];
      default:
        return <Widget>[
          const Text(
            'ไม่มีการดำเนินการที่อนุญาตสำหรับงานนี้',
            style: AppType.bodySecondary,
          ),
        ];
    }
  }

  Widget _photoSection() {
    if (_hasPhotoEvidence) {
      return Container(
        width: double.infinity,
        padding: const EdgeInsets.all(AppSpacing.md),
        decoration: BoxDecoration(
          color: AppColors.successSurface,
          borderRadius: BorderRadius.circular(AppRadius.md),
        ),
        child: Row(
          children: <Widget>[
            const Icon(
              Icons.check_circle_outline,
              color: AppColors.success,
              size: AppSpacing.xl,
            ),
            const SizedBox(width: AppSpacing.sm),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: <Widget>[
                  Text(
                    'แนบหลักฐานแล้ว',
                    style: AppType.label.copyWith(color: AppColors.success),
                  ),
                  const SizedBox(height: AppSpacing.xs),
                  Text(
                    _attachedFilename ?? 'ไฟล์หลักฐาน',
                    key: const Key('task-photo-name'),
                    style: AppType.caption,
                  ),
                ],
              ),
            ),
            if (_attachedDocumentId != null)
              TextButton(
                key: const Key('task-open-file'),
                onPressed: _openingFile ? null : _openAttachedFile,
                child: Text(_openingFile ? 'กำลังเปิด...' : 'ดูไฟล์'),
              ),
          ],
        ),
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        Text('แนบรูปหลักฐาน', style: AppType.sectionTitle),
        const SizedBox(height: AppSpacing.xs),
        const Text('ภาพถ่ายหลักฐานจำเป็นก่อนปิดงานนี้', style: AppType.caption),
        const SizedBox(height: AppSpacing.md),
        Wrap(
          spacing: AppSpacing.sm,
          runSpacing: AppSpacing.sm,
          children: <Widget>[
            OutlinedButton.icon(
              key: const Key('task-pick-camera'),
              onPressed: _busy || _uploading
                  ? null
                  : () => _attachPhoto(PhotoOrigin.camera),
              icon: const Icon(Icons.photo_camera_outlined),
              label: const Text('ถ่ายรูป'),
            ),
            OutlinedButton.icon(
              key: const Key('task-pick-gallery'),
              onPressed: _busy || _uploading
                  ? null
                  : () => _attachPhoto(PhotoOrigin.gallery),
              icon: const Icon(Icons.photo_library_outlined),
              label: const Text('เลือกจากคลังภาพ'),
            ),
          ],
        ),
        if (_uploading) ...<Widget>[
          const SizedBox(height: AppSpacing.md),
          LinearProgressIndicator(
            key: const Key('task-upload-progress'),
            value: _uploadProgress == null ? null : _uploadProgress! / 100,
            color: AppColors.info,
            backgroundColor: AppColors.infoSurface,
          ),
          const SizedBox(height: AppSpacing.xs),
          Text('กำลังอัปโหลด ${_uploadProgress ?? 0}%', style: AppType.caption),
        ],
      ],
    );
  }

  ButtonStyle _primaryButtonStyle() {
    return FilledButton.styleFrom(
      backgroundColor: AppColors.info,
      foregroundColor: AppColors.textOnAccent,
      minimumSize: const Size.fromHeight(AppSpacing.xxxl),
      textStyle: AppType.button,
    );
  }
}

/// One inline sentence on a tinted surface (server message or local failure).
class _Notice extends StatelessWidget {
  const _Notice({
    super.key,
    required this.background,
    required this.foreground,
    required this.message,
  });

  final Color background;
  final Color foreground;
  final String message;

  @override
  Widget build(BuildContext context) {
    return Container(
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
