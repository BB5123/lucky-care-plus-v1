import '../../core/format/thai_date.dart';
import '../../core/network/json_read.dart';

/// Task tier — the server decides which transitions each tier permits
/// (ADR-001). The client only mirrors the labels and the action set.
enum TaskTier { routine, delegated, clinical }

extension TaskTierRules on TaskTier {
  String get apiValue {
    return switch (this) {
      TaskTier.routine => 'routine',
      TaskTier.delegated => 'delegated',
      TaskTier.clinical => 'clinical',
    };
  }

  String get label {
    return switch (this) {
      TaskTier.routine => 'งานประจำ',
      TaskTier.delegated => 'งานที่มอบหมาย',
      TaskTier.clinical => 'งานคลินิก',
    };
  }
}

String taskTierLabel(String tier) {
  return switch (tier) {
    'routine' => 'งานประจำ',
    'delegated' => 'งานที่มอบหมาย',
    'clinical' => 'งานคลินิก',
    _ => 'งานดูแล',
  };
}

String taskStatusLabel(String status) {
  return switch (status) {
    'scheduled' => 'รอเริ่ม',
    'open' => 'เปิดอยู่',
    'assigned' => 'มอบหมายแล้ว',
    'accepted' => 'รับงานแล้ว',
    'in_progress' => 'กำลังทำ',
    'blocked' => 'ติดขัด',
    'completed' => 'เสร็จแล้ว',
    'cancelled' => 'ยกเลิก',
    _ => 'ไม่ทราบสถานะ',
  };
}

/// The transitions this slice can send.
enum TaskAction { accept, start, complete }

extension TaskActionRules on TaskAction {
  String get path {
    return switch (this) {
      TaskAction.accept => 'accept',
      TaskAction.start => 'start',
      TaskAction.complete => 'complete',
    };
  }
}

/// One care task from `GET .../care-tasks`.
class CareTask {
  const CareTask({
    required this.id,
    required this.title,
    required this.tier,
    required this.status,
    this.profileId = '',
    this.type = '',
    this.priority = 'normal',
    this.dueAt,
    this.profileDisplayName,
    this.version = 1,
    this.evidencePolicy = '',
    this.verification = '',
    this.blockedReason,
  });

  final String id;
  final String title;
  final String tier;
  final String status;

  /// The health profile this task belongs to. Documents and evidence are
  /// profile-scoped, so the upload must address the task's own profile, not
  /// whichever profile happens to be selected in another tab.
  final String profileId;
  final String type;
  final String priority;
  final String? dueAt;
  final String? profileDisplayName;
  final int version;
  final String evidencePolicy;
  final String verification;
  final String? blockedReason;

  String get tierLabel => taskTierLabel(tier);

  String get dueLabel {
    final String? value = dueAt;

    return value == null ? '' : formatThaiDateTime(value);
  }

  DateTime? get dueAtDate {
    final String? value = dueAt;

    return value == null ? null : DateTime.tryParse(value);
  }

  /// `completed` and `cancelled` leave the list; everything else is shown.
  bool get isTerminal => status == 'completed' || status == 'cancelled';

  bool get isClinical => tier == 'clinical';

  /// `photo` and `both` require a photo before completion; the server enforces
  /// the same rule (`assertRequiredEvidence`). `value`/`none` are not
  /// attachable from this sheet.
  bool get requiresPhotoEvidence =>
      evidencePolicy == 'photo' || evidencePolicy == 'both';

  factory CareTask.fromJson(Map<String, dynamic> json) {
    return CareTask(
      id: readString(json['id']),
      title: readString(json['title']),
      tier: readString(json['tier'], fallback: 'routine'),
      status: readString(json['status'], fallback: 'open'),
      profileId: readString(json['profile_id']),
      type: readString(json['type']),
      priority: readString(json['priority'], fallback: 'normal'),
      dueAt: readNullableString(json['due_at']),
      profileDisplayName: readNullableString(json['profile_display_name']),
      version: readInt(json['version'], fallback: 1),
      evidencePolicy: readString(json['evidence_policy']),
      verification: readString(json['verification']),
      blockedReason: readNullableString(json['blocked_reason']),
    );
  }
}

/// One evidence row from `POST .../care-tasks/{task}/evidence`.
///
/// A photo row is only meaningful with a [documentId]; the server refuses to
/// count a bare `photo` label as evidence (CT-005).
class TaskEvidence {
  const TaskEvidence({required this.id, required this.type, this.documentId});

  final String id;
  final String type;
  final String? documentId;

  factory TaskEvidence.fromJson(Map<String, dynamic> json) {
    return TaskEvidence(
      id: readString(json['id']),
      type: readString(json['type']),
      documentId: readNullableString(json['document_id']),
    );
  }
}

/// The three groups the tasks tab renders, in this fixed order.
enum TaskBucket { overdue, today, later }

extension TaskBucketRules on TaskBucket {
  String get label {
    return switch (this) {
      TaskBucket.overdue => 'เกินกำหนด',
      TaskBucket.today => 'วันนี้',
      TaskBucket.later => 'ภายหลัง',
    };
  }
}

class TaskGroup {
  const TaskGroup({required this.bucket, required this.tasks});

  final TaskBucket bucket;
  final List<CareTask> tasks;
}

/// Groups non-terminal tasks into overdue → today → later.
///
/// A task is overdue when its due time is already in the past (including
/// earlier today); today when it is due later on the same local day; later
/// when it falls on a future day or has no due time.
List<TaskGroup> groupTasks(List<CareTask> tasks, DateTime now) {
  final DateTime today = DateTime(now.year, now.month, now.day);
  final DateTime tomorrow = today.add(const Duration(days: 1));
  final Map<TaskBucket, List<CareTask>> grouped = <TaskBucket, List<CareTask>>{
    for (final TaskBucket bucket in TaskBucket.values) bucket: <CareTask>[],
  };

  for (final CareTask task in tasks) {
    if (task.isTerminal) {
      continue;
    }

    final DateTime? due = task.dueAtDate;

    if (due == null) {
      grouped[TaskBucket.later]!.add(task);
    } else if (due.isBefore(now)) {
      grouped[TaskBucket.overdue]!.add(task);
    } else if (due.isBefore(tomorrow)) {
      grouped[TaskBucket.today]!.add(task);
    } else {
      grouped[TaskBucket.later]!.add(task);
    }
  }

  for (final List<CareTask> bucket in grouped.values) {
    bucket.sort((CareTask left, CareTask right) {
      final DateTime? leftDue = left.dueAtDate;
      final DateTime? rightDue = right.dueAtDate;

      if (leftDue == null && rightDue == null) {
        return left.title.compareTo(right.title);
      }

      if (leftDue == null) {
        return 1;
      }

      if (rightDue == null) {
        return -1;
      }

      return leftDue.compareTo(rightDue);
    });
  }

  return <TaskGroup>[
    for (final TaskBucket bucket in TaskBucket.values)
      if (grouped[bucket]!.isNotEmpty)
        TaskGroup(
          bucket: bucket,
          tasks: List<CareTask>.unmodifiable(grouped[bucket]!),
        ),
  ];
}

/// What the tasks tab needs once the workspace context and list resolve.
class TasksData {
  TasksData({required this.hasWorkspace, required List<CareTask> tasks})
    : tasks = List<CareTask>.unmodifiable(tasks);

  const TasksData._({required this.hasWorkspace, required this.tasks});

  final bool hasWorkspace;
  final List<CareTask> tasks;

  bool get isEmpty => tasks.where((CareTask task) => !task.isTerminal).isEmpty;

  static const TasksData noWorkspace = TasksData._(
    hasWorkspace: false,
    tasks: <CareTask>[],
  );
}
