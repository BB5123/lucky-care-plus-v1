import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/api_failure.dart';
import '../../../design_system/status_chip.dart';
import '../../../design_system/tokens.dart';
import '../task_models.dart';
import '../tasks_controller.dart';

/// Task detail and the actions the tier allows.
///
/// The server owns every transition; this sheet only chooses which buttons to
/// show per tier and renders the 409/412 answer it gets back.
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

  Future<void> _run(TaskAction action, {String? outcome}) async {
    setState(() {
      _busy = true;
      _error = null;
      _conflict = false;
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
                onPressed: _busy
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
          if (_error != null) ...<Widget>[
            const SizedBox(height: AppSpacing.md),
            Container(
              key: const Key('task-action-error'),
              width: double.infinity,
              padding: const EdgeInsets.all(AppSpacing.md),
              decoration: BoxDecoration(
                color: AppColors.dangerSurface,
                borderRadius: BorderRadius.circular(AppRadius.md),
              ),
              child: Text(
                _error!,
                style: AppType.label.copyWith(color: AppColors.danger),
              ),
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
        // Photo upload is not in this slice. The action stays visible but
        // disabled with the reason, so the user learns the rule instead of
        // wondering where the button went (MOBILE_DESIGN §5, fail closed).
        return <Widget>[
          FilledButton(
            key: const Key('task-complete-button'),
            onPressed: null,
            style: _primaryButtonStyle(),
            child: const Text('ทำเสร็จแล้ว'),
          ),
          const SizedBox(height: AppSpacing.sm),
          Text(
            'ต้องแนบหลักฐานก่อน',
            key: const Key('task-evidence-reason'),
            style: AppType.label.copyWith(color: AppColors.warning),
          ),
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

  ButtonStyle _primaryButtonStyle() {
    return FilledButton.styleFrom(
      backgroundColor: AppColors.info,
      foregroundColor: AppColors.textOnAccent,
      minimumSize: const Size.fromHeight(AppSpacing.xxxl),
      textStyle: AppType.button,
    );
  }
}
