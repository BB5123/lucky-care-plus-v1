import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/api_failure.dart';
import '../../../design_system/tokens.dart';
import '../health_controller.dart';
import '../health_models.dart';

/// Bottom sheet for recording a blood-pressure, glucose or weight reading.
///
/// Client validation mirrors `MeasurementType` on the server, but the server
/// stays the judge: a 422 is rendered under the exact field it names.
class RecordMeasurementSheet extends ConsumerStatefulWidget {
  const RecordMeasurementSheet({super.key});

  static Future<void> show(BuildContext context) {
    return showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      backgroundColor: AppColors.surfaceRaised,
      builder: (BuildContext context) => const RecordMeasurementSheet(),
    );
  }

  @override
  ConsumerState<RecordMeasurementSheet> createState() =>
      _RecordMeasurementSheetState();
}

class _RecordMeasurementSheetState
    extends ConsumerState<RecordMeasurementSheet> {
  final GlobalKey<FormState> _formKey = GlobalKey<FormState>();
  final TextEditingController _systolic = TextEditingController();
  final TextEditingController _diastolic = TextEditingController();
  final TextEditingController _glucose = TextEditingController();
  final TextEditingController _weight = TextEditingController();

  MeasurementKind _kind = MeasurementKind.bloodPressure;
  GlucoseContext? _glucoseContext;
  Map<String, String> _serverErrors = const <String, String>{};
  String? _formError;
  bool _saving = false;

  @override
  void dispose() {
    _systolic.dispose();
    _diastolic.dispose();
    _glucose.dispose();
    _weight.dispose();
    super.dispose();
  }

  void _selectKind(MeasurementKind kind) {
    setState(() {
      _kind = kind;
      _serverErrors = const <String, String>{};
      _formError = null;
    });
  }

  void _clearServerError(String field) {
    if (!_serverErrors.containsKey(field)) {
      return;
    }

    setState(() {
      final Map<String, String> next = Map<String, String>.of(_serverErrors);
      next.remove(field);
      _serverErrors = next;
    });
  }

  String? _rangeError(
    String? raw, {
    required double minimum,
    required double maximum,
    required String requiredMessage,
    required String serverField,
  }) {
    final String? serverError = _serverErrors[serverField];

    if (serverError != null) {
      return serverError;
    }

    final String text = (raw ?? '').trim();

    if (text.isEmpty) {
      return requiredMessage;
    }

    final double? value = double.tryParse(text);

    if (value == null) {
      return 'กรุณากรอกตัวเลข';
    }

    if (value < minimum || value > maximum) {
      return 'ค่าต้องอยู่ระหว่าง ${_formatNumber(minimum)}–'
          '${_formatNumber(maximum)}';
    }

    return null;
  }

  String? _validateSystolic(String? raw) {
    final String? base = _rangeError(
      raw,
      minimum: 50,
      maximum: 300,
      requiredMessage: 'กรุณากรอกค่าความดันตัวบน',
      serverField: 'value',
    );

    if (base != null) {
      return base;
    }

    final double? systolic = double.tryParse((raw ?? '').trim());
    final double? diastolic = double.tryParse(_diastolic.text.trim());

    if (systolic != null && diastolic != null && systolic <= diastolic) {
      return 'ค่าความดันตัวบนต้องมากกว่าค่าตัวล่าง';
    }

    return null;
  }

  String? _validateDiastolic(String? raw) {
    return _rangeError(
      raw,
      minimum: 30,
      maximum: 200,
      requiredMessage: 'กรุณากรอกค่าความดันตัวล่าง',
      serverField: 'secondary_value',
    );
  }

  String? _validateGlucose(String? raw) {
    return _rangeError(
      raw,
      minimum: 20,
      maximum: 800,
      requiredMessage: 'กรุณากรอกค่าน้ำตาลในเลือด',
      serverField: 'value',
    );
  }

  String? _validateWeight(String? raw) {
    return _rangeError(
      raw,
      minimum: 1,
      maximum: 400,
      requiredMessage: 'กรุณากรอกค่าน้ำหนัก',
      serverField: 'value',
    );
  }

  String? _validateContext(GlucoseContext? value) {
    final String? serverError = _serverErrors['context'];

    if (serverError != null) {
      return serverError;
    }

    if (value == null) {
      return 'กรุณาเลือกช่วงเวลาของค่าน้ำตาล';
    }

    return null;
  }

  CreateMeasurementInput _buildInput() {
    switch (_kind) {
      case MeasurementKind.bloodPressure:
        return CreateMeasurementInput(
          type: _kind.apiValue,
          value: double.parse(_systolic.text.trim()),
          secondaryValue: double.parse(_diastolic.text.trim()),
        );
      case MeasurementKind.bloodGlucose:
        return CreateMeasurementInput(
          type: _kind.apiValue,
          value: double.parse(_glucose.text.trim()),
          context: _glucoseContext?.apiValue,
        );
      case MeasurementKind.weight:
        return CreateMeasurementInput(
          type: _kind.apiValue,
          value: double.parse(_weight.text.trim()),
        );
    }
  }

  Future<void> _submit() async {
    setState(() {
      _serverErrors = const <String, String>{};
      _formError = null;
    });

    final FormState? form = _formKey.currentState;

    if (form == null || !form.validate()) {
      return;
    }

    setState(() {
      _saving = true;
    });

    try {
      await ref.read(healthControllerProvider.notifier).record(_buildInput());

      if (!mounted) {
        return;
      }

      ScaffoldMessenger.of(context)
          .showSnackBar(const SnackBar(content: Text('บันทึกค่าเรียบร้อย')));
      Navigator.of(context).pop();
    } on ApiFailure catch (failure) {
      if (!mounted) {
        return;
      }

      final Set<String> shownFields = switch (_kind) {
        MeasurementKind.bloodPressure => const <String>{
          'value',
          'secondary_value',
        },
        MeasurementKind.bloodGlucose => const <String>{'value', 'context'},
        MeasurementKind.weight => const <String>{'value'},
      };
      final List<String> unhandled = failure.fieldErrors.entries
          .where((MapEntry<String, String> entry) {
            return !shownFields.contains(entry.key);
          })
          .map((MapEntry<String, String> entry) => entry.value)
          .toList(growable: false);

      setState(() {
        _saving = false;
        _serverErrors = failure.fieldErrors;
        _formError = unhandled.isNotEmpty
            ? unhandled.first
            : (failure.fieldErrors.isEmpty ? failure.message : null);
      });

      if (failure.fieldErrors.isNotEmpty) {
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
                    child: Text('บันทึกค่า', style: AppType.headline),
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
              const SizedBox(height: AppSpacing.md),
              SegmentedButton<MeasurementKind>(
                segments: MeasurementKind.values
                    .map(
                      (MeasurementKind kind) => ButtonSegment<MeasurementKind>(
                        value: kind,
                        label: Text(kind.shortLabel),
                      ),
                    )
                    .toList(growable: false),
                selected: <MeasurementKind>{_kind},
                onSelectionChanged: (Set<MeasurementKind> selection) {
                  _selectKind(selection.first);
                },
              ),
              const SizedBox(height: AppSpacing.lg),
              ..._fields(),
              if (_formError != null) ...<Widget>[
                const SizedBox(height: AppSpacing.md),
                Container(
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
                key: const Key('health-save'),
                onPressed: _saving ? null : _submit,
                style: FilledButton.styleFrom(
                  backgroundColor: AppColors.info,
                  foregroundColor: AppColors.textOnAccent,
                  minimumSize: const Size.fromHeight(AppSpacing.xxxl),
                  textStyle: AppType.button,
                ),
                child: Text(_saving ? 'กำลังบันทึก…' : 'บันทึก'),
              ),
            ],
          ),
        ),
      ),
    );
  }

  List<Widget> _fields() {
    switch (_kind) {
      case MeasurementKind.bloodPressure:
        return <Widget>[
          TextFormField(
            key: const Key('health-bp-systolic'),
            controller: _systolic,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            textInputAction: TextInputAction.next,
            decoration: const InputDecoration(
              labelText: 'ค่าความดันตัวบน',
              helperText: 'mmHg',
              border: OutlineInputBorder(),
              errorMaxLines: 3,
            ),
            onChanged: (String value) => _clearServerError('value'),
            validator: _validateSystolic,
          ),
          const SizedBox(height: AppSpacing.md),
          TextFormField(
            key: const Key('health-bp-diastolic'),
            controller: _diastolic,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            textInputAction: TextInputAction.done,
            decoration: const InputDecoration(
              labelText: 'ค่าความดันตัวล่าง',
              helperText: 'mmHg',
              border: OutlineInputBorder(),
              errorMaxLines: 3,
            ),
            onChanged: (String value) => _clearServerError('secondary_value'),
            validator: _validateDiastolic,
          ),
        ];
      case MeasurementKind.bloodGlucose:
        return <Widget>[
          TextFormField(
            key: const Key('health-glucose-value'),
            controller: _glucose,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            textInputAction: TextInputAction.done,
            decoration: const InputDecoration(
              labelText: 'ค่าน้ำตาลในเลือด',
              helperText: 'mg/dL',
              border: OutlineInputBorder(),
              errorMaxLines: 3,
            ),
            onChanged: (String value) => _clearServerError('value'),
            validator: _validateGlucose,
          ),
          const SizedBox(height: AppSpacing.md),
          DropdownButtonFormField<GlucoseContext>(
            key: const Key('health-glucose-context'),
            initialValue: _glucoseContext,
            decoration: const InputDecoration(
              labelText: 'ช่วงเวลาที่วัด',
              border: OutlineInputBorder(),
              errorMaxLines: 3,
            ),
            items: GlucoseContext.values
                .map(
                  (GlucoseContext context) => DropdownMenuItem<GlucoseContext>(
                    value: context,
                    child: Text(context.label),
                  ),
                )
                .toList(growable: false),
            onChanged: (GlucoseContext? value) {
              setState(() {
                _glucoseContext = value;
              });
              _clearServerError('context');
            },
            validator: _validateContext,
          ),
        ];
      case MeasurementKind.weight:
        return <Widget>[
          TextFormField(
            key: const Key('health-weight-value'),
            controller: _weight,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            textInputAction: TextInputAction.done,
            decoration: const InputDecoration(
              labelText: 'ค่าน้ำหนัก',
              helperText: 'kg',
              border: OutlineInputBorder(),
              errorMaxLines: 3,
            ),
            onChanged: (String value) => _clearServerError('value'),
            validator: _validateWeight,
          ),
        ];
    }
  }

  static String _formatNumber(double value) {
    if (value == value.roundToDouble()) {
      return value.toInt().toString();
    }

    return value.toString();
  }
}
