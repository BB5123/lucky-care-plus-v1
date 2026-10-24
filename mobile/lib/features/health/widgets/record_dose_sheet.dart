import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/medication/medication_models.dart';
import '../../../core/network/api_failure.dart';
import '../../../design_system/tokens.dart';
import '../medication_controller.dart';

/// Bottom sheet for reporting one dose as taken or skipped.
///
/// A skipped dose requires a note that explains why. That rule is enforced
/// here before the controller (and therefore the repository) is called, and it
/// mirrors the server so a known-invalid request is never sent.
class RecordDoseSheet extends ConsumerStatefulWidget {
  const RecordDoseSheet({super.key, required this.plan});

  final MedicationPlan plan;

  static Future<void> show(BuildContext context, MedicationPlan plan) {
    return showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      backgroundColor: AppColors.surfaceRaised,
      builder: (BuildContext context) => RecordDoseSheet(plan: plan),
    );
  }

  @override
  ConsumerState<RecordDoseSheet> createState() => _RecordDoseSheetState();
}

class _RecordDoseSheetState extends ConsumerState<RecordDoseSheet> {
  final GlobalKey<FormState> _formKey = GlobalKey<FormState>();
  final TextEditingController _note = TextEditingController();

  MedicationDoseStatus _status = MedicationDoseStatus.taken;
  String? _serverNoteError;
  String? _formError;
  bool _saving = false;

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  void _selectStatus(MedicationDoseStatus status) {
    setState(() {
      _status = status;
      _serverNoteError = null;
      _formError = null;
    });
  }

  String? _validateNote(String? raw) {
    final String? serverError = _serverNoteError;

    if (serverError != null) {
      return serverError;
    }

    if (_status != MedicationDoseStatus.skipped) {
      return null;
    }

    final String note = (raw ?? '').trim();

    if (note.isEmpty) {
      return 'กรุณาระบุเหตุผลที่ข้ามยา';
    }

    return null;
  }

  Future<void> _submit() async {
    setState(() {
      _serverNoteError = null;
      _formError = null;
    });

    final FormState? form = _formKey.currentState;

    if (form == null || !form.validate()) {
      return;
    }

    final String note = _note.text.trim();

    if (_status == MedicationDoseStatus.skipped && note.isEmpty) {
      return;
    }

    setState(() {
      _saving = true;
    });

    try {
      await ref
          .read(medicationControllerProvider.notifier)
          .recordDose(
            widget.plan,
            status: _status,
            note: note.isEmpty ? null : note,
          );

      if (!mounted) {
        return;
      }

      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('บันทึกการทานยาเรียบร้อย')),
      );
      Navigator.of(context).pop();
    } on ApiFailure catch (failure) {
      if (!mounted) {
        return;
      }

      setState(() {
        _saving = false;
        _serverNoteError = failure.fieldErrors['note'];
        _formError = _serverNoteError == null ? failure.message : null;
      });

      if (_serverNoteError != null) {
        form.validate();
      }
    } on Object {
      if (!mounted) {
        return;
      }

      setState(() {
        _saving = false;
        _formError = ApiFailure.genericMessage;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: SingleChildScrollView(
        padding: const EdgeInsets.all(AppSpacing.lg),
        child: Form(
          key: _formKey,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              Row(
                children: <Widget>[
                  const Expanded(
                    child: Text('บันทึกการทานยา', style: AppType.headline),
                  ),
                  IconButton(
                    tooltip: 'ปิด',
                    onPressed: _saving
                        ? null
                        : () {
                            Navigator.of(context).pop();
                          },
                    icon: const Icon(Icons.close),
                  ),
                ],
              ),
              const SizedBox(height: AppSpacing.sm),
              Text(
                '${widget.plan.name} · ${widget.plan.dosage}',
                key: const Key('dose-plan-name'),
                style: AppType.bodySecondary,
              ),
              const SizedBox(height: AppSpacing.lg),
              SegmentedButton<MedicationDoseStatus>(
                segments: MedicationDoseStatus.values
                    .map(
                      (MedicationDoseStatus status) =>
                          ButtonSegment<MedicationDoseStatus>(
                            value: status,
                            label: Text(
                              status.label,
                              key: Key('dose-status-${status.apiValue}'),
                            ),
                          ),
                    )
                    .toList(growable: false),
                selected: <MedicationDoseStatus>{_status},
                onSelectionChanged: (Set<MedicationDoseStatus> selection) {
                  _selectStatus(selection.first);
                },
              ),
              const SizedBox(height: AppSpacing.lg),
              TextFormField(
                key: const Key('dose-note'),
                controller: _note,
                maxLines: 3,
                maxLength: 2000,
                textInputAction: TextInputAction.done,
                decoration: InputDecoration(
                  labelText: _status == MedicationDoseStatus.skipped
                      ? 'เหตุผลที่ข้ามยา'
                      : 'หมายเหตุ (ไม่บังคับ)',
                  border: const OutlineInputBorder(),
                  errorMaxLines: 3,
                ),
                onChanged: (String value) {
                  if (_serverNoteError != null) {
                    setState(() {
                      _serverNoteError = null;
                    });
                  }
                },
                validator: _validateNote,
              ),
              if (_formError != null) ...<Widget>[
                const SizedBox(height: AppSpacing.sm),
                Container(
                  key: const Key('dose-form-error'),
                  width: double.infinity,
                  padding: const EdgeInsets.all(AppSpacing.md),
                  decoration: BoxDecoration(
                    color: AppColors.dangerSurface,
                    borderRadius: BorderRadius.circular(AppRadius.md),
                  ),
                  child: Text(
                    _formError!,
                    style: AppType.label.copyWith(color: AppColors.danger),
                  ),
                ),
              ],
              const SizedBox(height: AppSpacing.lg),
              FilledButton(
                key: const Key('dose-save'),
                onPressed: _saving ? null : _submit,
                style: FilledButton.styleFrom(
                  backgroundColor: AppColors.info,
                  foregroundColor: AppColors.textOnAccent,
                  minimumSize: const Size.fromHeight(AppSpacing.xxxl),
                  textStyle: AppType.button,
                ),
                child: Text(_saving ? 'กำลังบันทึก…' : 'บันทึก'),
              ),
              const SizedBox(height: AppSpacing.sm),
              const Text(
                'การทานยาที่บันทึกเป็นการรายงานด้วยตนเอง ไม่ใช่หลักฐานทางการแพทย์',
                key: Key('dose-self-reported-note'),
                style: AppType.caption,
              ),
            ],
          ),
        ),
      ),
    );
  }
}
