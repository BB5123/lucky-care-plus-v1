import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/ai/ai_models.dart';
import '../../../core/ai/ai_repository.dart';
import '../../../core/documents/document_models.dart';
import '../../../core/documents/documents_repository.dart';
import '../../../core/media/photo_source.dart';
import '../../../core/medication/medication_models.dart';
import '../../../core/network/api_failure.dart';
import '../../../core/workspace/current_workspace.dart';
import '../../../design_system/tokens.dart';
import '../health_controller.dart';
import '../health_models.dart';
import '../medication_controller.dart';
import 'ai_state_views.dart';

/// Scan → upload → extract → review → save (TASK OCR-2).
///
/// The flow has two hard rules:
///
///  1. Upload happens first; the extraction is called only with the document id
///     the upload returned. A failed upload never reaches the AI endpoint.
///  2. A successful extraction is a **draft**. Nothing is written until the
///     user presses "ยืนยันและบันทึก", and that save goes through the normal
///     medication or measurement endpoint. The AI never writes (AIG-007).
///
/// The sheet renders only the values of [AiExtractionDraft]: the draft drops
/// `document_id` and any key outside the closed schema, so a raw record value,
/// a patient name or a document identifier cannot be shown by accident (N-02).
class OcrScanSheet extends ConsumerStatefulWidget {
  const OcrScanSheet({super.key, required this.kind});

  final AiExtractionKind kind;

  static Future<void> show(BuildContext context, AiExtractionKind kind) {
    return showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      backgroundColor: AppColors.surfaceRaised,
      builder: (BuildContext context) => OcrScanSheet(kind: kind),
    );
  }

  @override
  ConsumerState<OcrScanSheet> createState() => _OcrScanSheetState();
}

class _OcrScanSheetState extends ConsumerState<OcrScanSheet> {
  final GlobalKey<FormState> _formKey = GlobalKey<FormState>();

  static final RegExp _timePattern = RegExp(r'^(?:[01]\d|2[0-3]):[0-5]\d$');
  static final RegExp _datePattern = RegExp(r'^\d{4}-\d{2}-\d{2}$');

  bool _working = false;
  bool _saving = false;
  bool _visionUnavailable = false;
  bool _aiUnavailable = false;
  String? _error;
  String _status = '';
  int _uploadProgress = 0;

  AiExtractionDraft? _draft;

  TextEditingController? _name;
  TextEditingController? _dosage;
  TextEditingController? _dosesPerDay;
  TextEditingController? _scheduleTimes;
  TextEditingController? _instructions;
  TextEditingController? _startsOn;
  String _form = 'tablet';

  MeasurementKind? _deviceKind;
  TextEditingController? _value;
  TextEditingController? _secondaryValue;
  TextEditingController? _measuredAt;
  GlucoseContext? _glucoseContext;

  bool get _isMedication => widget.kind == AiExtractionKind.medicationLabel;

  String get _scanTitle {
    return _isMedication ? 'สแกนซองยา' : 'สแกนหน้าจอเครื่องวัด';
  }

  @override
  void dispose() {
    _disposeDraftControllers();
    super.dispose();
  }

  void _disposeDraftControllers() {
    _name?.dispose();
    _dosage?.dispose();
    _dosesPerDay?.dispose();
    _scheduleTimes?.dispose();
    _instructions?.dispose();
    _startsOn?.dispose();
    _value?.dispose();
    _secondaryValue?.dispose();
    _measuredAt?.dispose();
    _name = null;
    _dosage = null;
    _dosesPerDay = null;
    _scheduleTimes = null;
    _instructions = null;
    _startsOn = null;
    _value = null;
    _secondaryValue = null;
    _measuredAt = null;
  }

  Future<void> _pick(PhotoOrigin origin) async {
    if (_working || _saving) {
      return;
    }

    setState(() {
      _error = null;
      _working = true;
      _status = 'กำลังเปิดกล้อง…';
      _uploadProgress = 0;
    });

    PickedPhoto? picked;

    try {
      picked = await ref.read(photoSourceProvider).pick(origin);
    } on Object {
      if (!mounted) {
        return;
      }

      setState(() {
        _working = false;
        _status = '';
        _error = 'ไม่สามารถเปิดกล้องหรือคลังภาพได้ กรุณาลองใหม่';
      });

      return;
    }

    if (!mounted) {
      return;
    }

    if (picked == null) {
      setState(() {
        _working = false;
        _status = '';
      });

      return;
    }

    await _uploadAndExtract(picked);
  }

  /// Upload first, then extract with the returned id. A 422/503 from the upload
  /// leaves no AI call behind.
  Future<void> _uploadAndExtract(PickedPhoto photo) async {
    setState(() {
      _working = true;
      _status = 'กำลังอัปโหลดภาพ…';
      _uploadProgress = 0;
      _error = null;
    });

    try {
      final WorkspaceContext workspace = await ref.read(
        currentWorkspaceProvider.future,
      );
      final String profileId = workspace.profileId ?? '';

      if (!workspace.hasWorkspace || profileId.isEmpty) {
        if (mounted) {
          setState(() {
            _error = 'ไม่พบโปรไฟล์สุขภาพสำหรับสแกนภาพ';
          });
        }

        return;
      }

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

      setState(() {
        _uploadProgress = 100;
        _status = 'กำลังอ่านข้อมูลจากภาพ…';
      });

      final AiExtractionDraft draft = await ref
          .read(aiRepositoryProvider)
          .extract(
            workspace,
            documentId: document.id,
            kind: widget.kind,
            profileId: profileId,
          );

      if (!mounted) {
        return;
      }

      _applyDraft(draft);
    } on DocumentUploadException catch (failure) {
      if (mounted) {
        setState(() => _error = failure.message);
      }
    } on AiVisionUnavailable {
      if (mounted) {
        setState(() => _visionUnavailable = true);
      }
    } on AiUnavailable {
      if (mounted) {
        setState(() => _aiUnavailable = true);
      }
    } on AiQuotaExceeded {
      if (mounted) {
        setState(() => _error = aiQuotaMessage(null));
      }
    } on ApiFailure catch (failure) {
      if (mounted) {
        setState(() => _error = failure.message);
      }
    } on Object {
      if (mounted) {
        setState(() => _error = ApiFailure.genericMessage);
      }
    } finally {
      if (mounted) {
        setState(() {
          _working = false;
          _status = '';
          _uploadProgress = 0;
        });
      }
    }
  }

  /// Creates the editable form from the draft. Only draft values are used; a
  /// value the server dropped (and reported in `warnings`) stays empty.
  void _applyDraft(AiExtractionDraft draft) {
    _disposeDraftControllers();

    setState(() {
      _draft = draft;
      _error = null;
      _visionUnavailable = false;
      _aiUnavailable = false;

      if (draft.isMedication) {
        _name = TextEditingController(text: draft.stringValue('name'));
        _dosage = TextEditingController(text: draft.stringValue('dosage'));
        _dosesPerDay = TextEditingController(
          text: _numberText(draft.intValue('doses_per_day')?.toDouble()),
        );
        _scheduleTimes = TextEditingController(
          text: draft.stringListValue('schedule_times').join(', '),
        );
        _instructions = TextEditingController(
          text: draft.stringValue('instructions'),
        );
        _startsOn = TextEditingController(text: _todayIso());
        _form = 'tablet';
      } else {
        _deviceKind = measurementKindForApiValue(draft.stringValue('type'));
        _value = TextEditingController(
          text: _numberText(draft.numberValue('value')),
        );
        _secondaryValue = TextEditingController(
          text: _numberText(draft.numberValue('secondary_value')),
        );
        _measuredAt = TextEditingController(
          text: draft.stringValue('measured_at'),
        );
        _glucoseContext = null;
      }
    });
  }

  void _resetScan() {
    _disposeDraftControllers();

    setState(() {
      _draft = null;
      _error = null;
      _visionUnavailable = false;
      _aiUnavailable = false;
      _status = '';
      _uploadProgress = 0;
      _deviceKind = null;
      _glucoseContext = null;
      _form = 'tablet';
    });
  }

  Future<void> _confirm() async {
    if (_saving) {
      return;
    }

    setState(() => _error = null);

    final FormState? form = _formKey.currentState;

    if (form == null || !form.validate()) {
      return;
    }

    setState(() => _saving = true);

    try {
      if (_isMedication) {
        await ref
            .read(medicationControllerProvider.notifier)
            .createPlan(_medicationInput());
      } else {
        await ref
            .read(healthControllerProvider.notifier)
            .record(_measurementInput());
      }

      if (!mounted) {
        return;
      }

      final ScaffoldMessengerState messenger = ScaffoldMessenger.of(context);
      Navigator.of(context).pop();
      messenger.showSnackBar(
        const SnackBar(content: Text('บันทึกเรียบร้อยแล้ว')),
      );
    } on ApiFailure catch (failure) {
      if (!mounted) {
        return;
      }

      final String message = failure.fieldErrors.isNotEmpty
          ? failure.fieldErrors.values.first
          : failure.message;

      setState(() {
        _saving = false;
        _error = message;
      });
    } on Object {
      if (!mounted) {
        return;
      }

      setState(() {
        _saving = false;
        _error = ApiFailure.genericMessage;
      });
    }
  }

  CreateMedicationPlanInput _medicationInput() {
    return CreateMedicationPlanInput(
      name: _name!.text.trim(),
      dosage: _emptyToNull(_dosage!.text),
      form: _form,
      dosesPerDay: int.parse(_dosesPerDay!.text.trim()),
      scheduleTimes: _parseTimes(_scheduleTimes!.text),
      startsOn: _startsOn!.text.trim(),
      instructions: _emptyToNull(_instructions!.text),
    );
  }

  CreateMeasurementInput _measurementInput() {
    final MeasurementKind kind = _deviceKind!;

    return CreateMeasurementInput(
      type: kind.apiValue,
      value: double.parse(_value!.text.trim()),
      secondaryValue: kind.requiresSecondary
          ? double.parse(_secondaryValue!.text.trim())
          : null,
      context: kind.requiresContext ? _glucoseContext?.apiValue : null,
      recordedAt: _emptyToNull(_measuredAt!.text),
    );
  }

  @override
  Widget build(BuildContext context) {
    if (_visionUnavailable) {
      return _BlockedContent(
        onClose: _close,
        child: const AiVisionUnavailableCard(),
      );
    }

    if (_aiUnavailable) {
      return _BlockedContent(
        onClose: _close,
        child: const AiUnavailableCard(),
      );
    }

    final AiExtractionDraft? draft = _draft;

    if (draft != null) {
      return _draftSheet(draft);
    }

    return _photoSheet();
  }

  void _close() {
    if (_working || _saving) {
      return;
    }

    Navigator.of(context).pop();
  }

  Widget _photoSheet() {
    return SingleChildScrollView(
      key: const Key('ocr-photo-sheet'),
      padding: const EdgeInsets.all(AppSpacing.lg),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Row(
            children: <Widget>[
              Expanded(child: Text(_scanTitle, style: AppType.headline)),
              IconButton(
                tooltip: 'ปิด',
                onPressed: _working ? null : _close,
                icon: const Icon(Icons.close),
              ),
            ],
          ),
          const SizedBox(height: AppSpacing.sm),
          Text(
            _isMedication
                ? 'ถ่ายภาพซองยาหรือฉลากยา แล้วตรวจสอบข้อมูลก่อนบันทึก'
                : 'ถ่ายภาพหน้าจอเครื่องวัด แล้วตรวจสอบค่าก่อนบันทึก',
            style: AppType.bodySecondary,
          ),
          const SizedBox(height: AppSpacing.lg),
          Wrap(
            spacing: AppSpacing.sm,
            runSpacing: AppSpacing.sm,
            children: <Widget>[
              OutlinedButton.icon(
                key: const Key('ocr-pick-camera'),
                onPressed: _working
                    ? null
                    : () => _pick(PhotoOrigin.camera),
                icon: const Icon(Icons.photo_camera_outlined),
                label: const Text('ถ่ายรูป'),
              ),
              OutlinedButton.icon(
                key: const Key('ocr-pick-gallery'),
                onPressed: _working
                    ? null
                    : () => _pick(PhotoOrigin.gallery),
                icon: const Icon(Icons.photo_library_outlined),
                label: const Text('คลังภาพ'),
              ),
            ],
          ),
          if (_working) ...<Widget>[
            const SizedBox(height: AppSpacing.lg),
            LinearProgressIndicator(
              key: const Key('ocr-upload-progress'),
              value: _uploadProgress / 100,
              color: AppColors.info,
              backgroundColor: AppColors.infoSurface,
            ),
            const SizedBox(height: AppSpacing.xs),
            Text(_status, style: AppType.caption),
          ],
          if (_error != null) ...<Widget>[
            const SizedBox(height: AppSpacing.md),
            _Notice(message: _error!),
          ],
        ],
      ),
    );
  }

  Widget _draftSheet(AiExtractionDraft draft) {
    return SingleChildScrollView(
      key: const Key('ocr-draft-sheet'),
      padding: EdgeInsets.fromLTRB(
        AppSpacing.lg,
        AppSpacing.lg,
        AppSpacing.lg,
        AppSpacing.lg + MediaQuery.viewInsetsOf(context).bottom,
      ),
      child: Form(
        key: _formKey,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            Row(
              children: <Widget>[
                const Expanded(
                  child: Text('ตรวจสอบข้อมูลจากภาพ', style: AppType.headline),
                ),
                IconButton(
                  tooltip: 'ปิด',
                  onPressed: _saving ? null : _close,
                  icon: const Icon(Icons.close),
                ),
              ],
            ),
            const SizedBox(height: AppSpacing.sm),
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(AppSpacing.md),
              decoration: BoxDecoration(
                color: AppColors.infoSurface,
                borderRadius: BorderRadius.circular(AppRadius.md),
              ),
              child: const Text(
                'ร่างจากภาพ - ตรวจสอบก่อนบันทึก',
                key: Key('ocr-attribution'),
                style: AppType.label,
              ),
            ),
            if (draft.warnings.isNotEmpty) ...<Widget>[
              const SizedBox(height: AppSpacing.md),
              _WarningCard(warnings: draft.warnings),
            ],
            const SizedBox(height: AppSpacing.lg),
            if (draft.isMedication) ..._medicationFields() else ..._deviceFields(),
            if (_error != null) ...<Widget>[
              const SizedBox(height: AppSpacing.md),
              _Notice(message: _error!),
            ],
            const SizedBox(height: AppSpacing.lg),
            FilledButton(
              key: const Key('ocr-confirm'),
              onPressed: _saving ? null : _confirm,
              style: FilledButton.styleFrom(
                backgroundColor: AppColors.info,
                foregroundColor: AppColors.textOnAccent,
                minimumSize: const Size.fromHeight(AppSpacing.xxxl),
                textStyle: AppType.button,
              ),
              child: Text(_saving ? 'กำลังบันทึก…' : 'ยืนยันและบันทึก'),
            ),
            const SizedBox(height: AppSpacing.sm),
            Row(
              children: <Widget>[
                Expanded(
                  child: OutlinedButton(
                    key: const Key('ocr-rescan'),
                    onPressed: _saving ? null : _resetScan,
                    child: const Text('สแกนใหม่'),
                  ),
                ),
                const SizedBox(width: AppSpacing.sm),
                Expanded(
                  child: OutlinedButton(
                    key: const Key('ocr-cancel-draft'),
                    onPressed: _saving ? null : _close,
                    child: const Text('ยกเลิก'),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  List<Widget> _medicationFields() {
    final TextEditingController name = _name!;
    final TextEditingController dosage = _dosage!;
    final TextEditingController dosesPerDay = _dosesPerDay!;
    final TextEditingController scheduleTimes = _scheduleTimes!;
    final TextEditingController startsOn = _startsOn!;
    final TextEditingController instructions = _instructions!;

    return <Widget>[
      TextFormField(
        key: const Key('ocr-field-name'),
        controller: name,
        textInputAction: TextInputAction.next,
        decoration: const InputDecoration(
          labelText: 'ชื่อยา',
          border: OutlineInputBorder(),
          errorMaxLines: 3,
        ),
        validator: _required('กรุณาตรวจสอบชื่อยาก่อนบันทึก'),
      ),
      const SizedBox(height: AppSpacing.md),
      TextFormField(
        key: const Key('ocr-field-dosage'),
        controller: dosage,
        textInputAction: TextInputAction.next,
        decoration: const InputDecoration(
          labelText: 'ขนาดยา',
          helperText: 'เช่น 5 mg',
          border: OutlineInputBorder(),
          errorMaxLines: 3,
        ),
      ),
      const SizedBox(height: AppSpacing.md),
      DropdownButtonFormField<String>(
        key: const Key('ocr-field-form'),
        initialValue: _form,
        decoration: const InputDecoration(
          labelText: 'รูปแบบยา',
          border: OutlineInputBorder(),
        ),
        items: medicationFormLabels.entries
            .map(
              (MapEntry<String, String> entry) =>
                  DropdownMenuItem<String>(
                    value: entry.key,
                    child: Text(entry.value),
                  ),
            )
            .toList(growable: false),
        onChanged: (String? value) {
          if (value != null) {
            setState(() => _form = value);
          }
        },
      ),
      const SizedBox(height: AppSpacing.md),
      TextFormField(
        key: const Key('ocr-field-doses-per-day'),
        controller: dosesPerDay,
        keyboardType: TextInputType.number,
        textInputAction: TextInputAction.next,
        decoration: const InputDecoration(
          labelText: 'จำนวนครั้งต่อวัน',
          helperText: '1–12 ครั้ง',
          border: OutlineInputBorder(),
          errorMaxLines: 3,
        ),
        validator: _validateDosesPerDay,
      ),
      const SizedBox(height: AppSpacing.md),
      TextFormField(
        key: const Key('ocr-field-schedule-times'),
        controller: scheduleTimes,
        textInputAction: TextInputAction.next,
        decoration: const InputDecoration(
          labelText: 'เวลาทานยา',
          helperText: 'คั่นด้วยจุลภาค เช่น 08:00, 20:00',
          border: OutlineInputBorder(),
          errorMaxLines: 3,
        ),
        validator: _validateScheduleTimes,
      ),
      const SizedBox(height: AppSpacing.md),
      TextFormField(
        key: const Key('ocr-field-starts-on'),
        controller: startsOn,
        textInputAction: TextInputAction.next,
        decoration: const InputDecoration(
          labelText: 'วันที่เริ่มใช้',
          helperText: 'ปี-เดือน-วัน เช่น 2026-10-25',
          border: OutlineInputBorder(),
          errorMaxLines: 3,
        ),
        validator: _validateDate,
      ),
      const SizedBox(height: AppSpacing.md),
      TextFormField(
        key: const Key('ocr-field-instructions'),
        controller: instructions,
        maxLines: 3,
        decoration: const InputDecoration(
          labelText: 'คำแนะนำจากฉลาก',
          border: OutlineInputBorder(),
          errorMaxLines: 3,
        ),
      ),
    ];
  }

  List<Widget> _deviceFields() {
    final TextEditingController value = _value!;
    final TextEditingController secondaryValue = _secondaryValue!;
    final TextEditingController measuredAt = _measuredAt!;
    final MeasurementKind? kind = _deviceKind;

    return <Widget>[
      DropdownButtonFormField<MeasurementKind>(
        key: const Key('ocr-field-type'),
        initialValue: kind,
        decoration: const InputDecoration(
          labelText: 'ชนิดค่าที่วัด',
          border: OutlineInputBorder(),
        ),
        items: MeasurementKind.values
            .map(
              (MeasurementKind option) =>
                  DropdownMenuItem<MeasurementKind>(
                    value: option,
                    child: Text(option.label),
                  ),
            )
            .toList(growable: false),
        onChanged: (MeasurementKind? selected) {
          setState(() {
            _deviceKind = selected;
            _glucoseContext = null;
          });
        },
        validator: (MeasurementKind? selected) =>
            selected == null ? 'กรุณาเลือกชนิดค่าที่วัด' : null,
      ),
      const SizedBox(height: AppSpacing.md),
      TextFormField(
        key: const Key('ocr-field-value'),
        controller: value,
        keyboardType: const TextInputType.numberWithOptions(decimal: true),
        textInputAction: TextInputAction.next,
        decoration: InputDecoration(
          labelText: _valueLabel(kind),
          helperText: kind?.unit ?? '',
          border: const OutlineInputBorder(),
          errorMaxLines: 3,
        ),
        validator: _validateDeviceValue,
      ),
      if (kind?.requiresSecondary ?? false) ...<Widget>[
        const SizedBox(height: AppSpacing.md),
        TextFormField(
          key: const Key('ocr-field-secondary-value'),
          controller: secondaryValue,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          textInputAction: TextInputAction.next,
          decoration: const InputDecoration(
            labelText: 'ค่าความดันตัวล่าง',
            helperText: 'mmHg',
            border: OutlineInputBorder(),
            errorMaxLines: 3,
          ),
          validator: _validateSecondaryValue,
        ),
      ],
      if (kind?.requiresContext ?? false) ...<Widget>[
        const SizedBox(height: AppSpacing.md),
        DropdownButtonFormField<GlucoseContext>(
          key: const Key('ocr-field-context'),
          initialValue: _glucoseContext,
          decoration: const InputDecoration(
            labelText: 'ช่วงเวลาที่วัด',
            border: OutlineInputBorder(),
            errorMaxLines: 3,
          ),
          items: GlucoseContext.values
              .map(
                (GlucoseContext option) =>
                    DropdownMenuItem<GlucoseContext>(
                      value: option,
                      child: Text(option.label),
                    ),
              )
              .toList(growable: false),
          onChanged: (GlucoseContext? selected) {
            setState(() => _glucoseContext = selected);
          },
          validator: (GlucoseContext? selected) =>
              selected == null ? 'กรุณาเลือกช่วงเวลาของค่าน้ำตาล' : null,
        ),
      ],
      const SizedBox(height: AppSpacing.md),
      TextFormField(
        key: const Key('ocr-field-measured-at'),
        controller: measuredAt,
        textInputAction: TextInputAction.done,
        decoration: const InputDecoration(
          labelText: 'เวลาที่วัด',
          helperText: 'เว้นว่างเพื่อใช้เวลาปัจจุบัน',
          border: OutlineInputBorder(),
          errorMaxLines: 3,
        ),
        validator: _validateMeasuredAt,
      ),
    ];
  }

  String _valueLabel(MeasurementKind? kind) {
    return switch (kind) {
      MeasurementKind.bloodPressure => 'ค่าความดันตัวบน',
      MeasurementKind.bloodGlucose => 'ค่าน้ำตาลในเลือด',
      MeasurementKind.weight => 'ค่าน้ำหนัก',
      null => 'ค่าที่อ่านได้',
    };
  }

  String? Function(String?) _required(String message) {
    return (String? raw) {
      if ((raw ?? '').trim().isEmpty) {
        return message;
      }

      return null;
    };
  }

  String? _validateDosesPerDay(String? raw) {
    final int? doses = int.tryParse((raw ?? '').trim());

    if (doses == null) {
      return 'กรุณากรอกจำนวนครั้งเป็นตัวเลข';
    }

    if (doses < 1 || doses > 12) {
      return 'จำนวนครั้งต่อวันต้องอยู่ระหว่าง 1–12';
    }

    return null;
  }

  String? _validateScheduleTimes(String? raw) {
    final List<String> times = _parseTimes(raw ?? '');

    if (times.isEmpty) {
      return 'กรุณาระบุเวลาทานยา';
    }

    for (final String time in times) {
      if (!_timePattern.hasMatch(time)) {
        return 'เวลาใช้รูปแบบ 24 ชั่วโมง เช่น 08:00';
      }
    }

    if (times.toSet().length != times.length) {
      return 'เวลาแต่ละมื้อต้องไม่ซ้ำกัน';
    }

    final int? doses = int.tryParse((_dosesPerDay?.text ?? '').trim());

    if (doses != null && times.length != doses) {
      return 'จำนวนเวลาต้องเท่ากับจำนวนครั้งต่อวัน';
    }

    return null;
  }

  String? _validateDate(String? raw) {
    final String text = (raw ?? '').trim();

    if (!_datePattern.hasMatch(text) || DateTime.tryParse(text) == null) {
      return 'กรุณาใช้รูปแบบปี-เดือน-วัน เช่น 2026-10-25';
    }

    return null;
  }

  String? _validateDeviceValue(String? raw) {
    final MeasurementKind? kind = _deviceKind;

    if (kind == null) {
      return 'กรุณาเลือกชนิดค่าที่วัด';
    }

    final String text = (raw ?? '').trim();

    if (text.isEmpty) {
      return 'กรุณาตรวจสอบค่าที่อ่านได้';
    }

    final double? value = double.tryParse(text);

    if (value == null) {
      return 'กรุณากรอกตัวเลข';
    }

    if (value < kind.minimum || value > kind.maximum) {
      return 'ค่าต้องอยู่ระหว่าง ${_formatNumber(kind.minimum)}–'
          '${_formatNumber(kind.maximum)}';
    }

    if (kind.requiresSecondary) {
      final double? secondary = double.tryParse(
        (_secondaryValue?.text ?? '').trim(),
      );

      if (secondary != null && value <= secondary) {
        return 'ค่าความดันตัวบนต้องมากกว่าค่าตัวล่าง';
      }
    }

    return null;
  }

  String? _validateSecondaryValue(String? raw) {
    final String text = (raw ?? '').trim();

    if (text.isEmpty) {
      return 'กรุณากรอกค่าความดันตัวล่าง';
    }

    final double? value = double.tryParse(text);

    if (value == null) {
      return 'กรุณากรอกตัวเลข';
    }

    if (value < 30 || value > 200) {
      return 'ค่าต้องอยู่ระหว่าง 30–200';
    }

    return null;
  }

  String? _validateMeasuredAt(String? raw) {
    final String text = (raw ?? '').trim();

    if (text.isEmpty) {
      return null;
    }

    if (DateTime.tryParse(text) == null) {
      return 'กรุณาตรวจสอบรูปแบบเวลา เช่น 2026-10-25T09:30:00';
    }

    return null;
  }

  static List<String> _parseTimes(String raw) {
    return raw
        .split(RegExp(r'[,\s]+'))
        .map((String item) => item.trim())
        .where((String item) => item.isNotEmpty)
        .toList(growable: false);
  }

  static String? _emptyToNull(String raw) {
    final String value = raw.trim();

    return value.isEmpty ? null : value;
  }

  static String _numberText(double? value) {
    if (value == null) {
      return '';
    }

    if (value == value.roundToDouble()) {
      return value.toInt().toString();
    }

    return value.toString();
  }

  static String _formatNumber(double value) {
    return _numberText(value);
  }

  static String _todayIso() {
    final DateTime now = DateTime.now();
    final String month = now.month.toString().padLeft(2, '0');
    final String day = now.day.toString().padLeft(2, '0');

    return '${now.year.toString().padLeft(4, '0')}-$month-$day';
  }
}

/// A calm card shown instead of the draft when the endpoint is unavailable.
class _BlockedContent extends StatelessWidget {
  const _BlockedContent({required this.onClose, required this.child});

  final VoidCallback onClose;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return SingleChildScrollView(
      key: const Key('ocr-blocked'),
      padding: const EdgeInsets.all(AppSpacing.lg),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          child,
          const SizedBox(height: AppSpacing.lg),
          OutlinedButton(
            key: const Key('ocr-blocked-close'),
            onPressed: onClose,
            child: const Text('ปิด'),
          ),
        ],
      ),
    );
  }
}

/// Server warnings, always shown before the fields.
///
/// A warning such as `value_out_of_range` means the server dropped the value;
/// the corresponding field is empty, so the sheet never presents a dropped
/// number as fact.
class _WarningCard extends StatelessWidget {
  const _WarningCard({required this.warnings});

  final List<String> warnings;

  @override
  Widget build(BuildContext context) {
    return Container(
      key: const Key('ocr-warnings'),
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
            'ข้อควรระวังจากภาพ',
            style: AppType.label.copyWith(color: AppColors.warning),
          ),
          const SizedBox(height: AppSpacing.xs),
          for (final String warning in warnings) ...<Widget>[
            Text(
              aiExtractionWarningMessage(warning),
              key: Key('ocr-warning-$warning'),
              style: AppType.caption,
            ),
            const SizedBox(height: AppSpacing.xs),
          ],
        ],
      ),
    );
  }
}

/// One inline Thai sentence on a tinted surface.
class _Notice extends StatelessWidget {
  const _Notice({required this.message});

  final String message;

  @override
  Widget build(BuildContext context) {
    return Container(
      key: const Key('ocr-error'),
      width: double.infinity,
      padding: const EdgeInsets.all(AppSpacing.md),
      decoration: BoxDecoration(
        color: AppColors.dangerSurface,
        borderRadius: BorderRadius.circular(AppRadius.md),
      ),
      child: Text(
        message,
        style: AppType.label.copyWith(color: AppColors.danger),
      ),
    );
  }
}
