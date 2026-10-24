import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/export/data_export_controller.dart';
import '../../../core/export/export_clipboard.dart';
import '../../../core/format/thai_date.dart';
import '../../../design_system/tokens.dart';

/// "ขอสำเนาข้อมูลของฉัน" for the current profile.
///
/// The card renders lifecycle metadata only: byte size, expiry and (once a
/// link has been minted) the short-lived URL. It never has access to a health
/// value or an object key, because the status resource does not contain them.
///
/// `url_launcher` is not a dependency of this app, so "ดาวน์โหลด" mints the
/// link and presents it in a selectable field with an explicit copy action.
class DataExportCard extends ConsumerStatefulWidget {
  const DataExportCard({super.key, required this.profileId});

  final String profileId;

  @override
  ConsumerState<DataExportCard> createState() => _DataExportCardState();
}

class _DataExportCardState extends ConsumerState<DataExportCard>
    with WidgetsBindingObserver {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    // The shell keeps every tab in an IndexedStack; TickerMode is false for
    // the branches that are not on screen, so a hidden card stops polling.
    ref
        .read(dataExportControllerProvider(widget.profileId).notifier)
        .setActive(TickerMode.valuesOf(context).enabled);
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    ref
        .read(dataExportControllerProvider(widget.profileId).notifier)
        .setActive(
          state == AppLifecycleState.resumed &&
              TickerMode.valuesOf(context).enabled,
        );
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final ExportState state = ref.watch(
      dataExportControllerProvider(widget.profileId),
    );
    final DataExportController controller = ref.read(
      dataExportControllerProvider(widget.profileId).notifier,
    );

    return Container(
      key: const Key('data-export-card'),
      width: double.infinity,
      padding: const EdgeInsets.all(AppSpacing.lg),
      decoration: BoxDecoration(
        color: AppColors.surfaceRaised,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(color: AppColors.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Text('ขอสำเนาข้อมูลของฉัน', style: AppType.title),
          const SizedBox(height: AppSpacing.xs),
          Text(
            'ไฟล์นี้รวมข้อมูลสุขภาพที่บันทึกไว้ในโปรไฟล์นี้ '
            'และไม่รวมรูปภาพเอกสาร (เก็บเฉพาะข้อมูลเมตาของเอกสาร)',
            key: const Key('export-explanation'),
            style: AppType.bodySecondary,
          ),
          const SizedBox(height: AppSpacing.md),
          if (state.permissionDenied)
            const _PermissionNotice()
          else if (state.isReady)
            _ready(state, controller)
          else if (state.isPending)
            _pending(state, controller)
          else
            _idle(state, controller),
        ],
      ),
    );
  }

  Widget _ready(ExportState state, DataExportController controller) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            Expanded(
              child: _Metric(
                label: 'ขนาดไฟล์',
                valueKey: const Key('export-size'),
                value: formatExportByteSize(state.byteSize),
              ),
            ),
            const SizedBox(width: AppSpacing.md),
            Expanded(
              child: _Metric(
                label: 'ใช้ได้ถึง',
                valueKey: const Key('export-expiry'),
                value: formatExportExpiry(state.expiresAt),
              ),
            ),
          ],
        ),
        const SizedBox(height: AppSpacing.sm),
        Text(
          'ลิงก์ดาวน์โหลดมีอายุสั้น กรุณาดาวน์โหลดทันที',
          style: AppType.caption,
        ),
        const SizedBox(height: AppSpacing.md),
        SizedBox(
          width: double.infinity,
          child: FilledButton(
            key: const Key('export-download'),
            onPressed: state.downloading ? null : controller.download,
            style: FilledButton.styleFrom(
              backgroundColor: AppColors.info,
              foregroundColor: AppColors.textOnAccent,
              minimumSize: const Size.fromHeight(AppSpacing.xxxl),
              textStyle: AppType.button,
            ),
            child: Text(state.downloading ? 'กำลังเปิดไฟล์...' : 'ดาวน์โหลด'),
          ),
        ),
        if (state.errorMessage != null) ...<Widget>[
          const SizedBox(height: AppSpacing.sm),
          Text(
            state.errorMessage!,
            key: const Key('export-error'),
            style: AppType.caption.copyWith(color: AppColors.danger),
          ),
        ],
        if (state.linkUrl != null) ...<Widget>[
          const SizedBox(height: AppSpacing.md),
          Text('ลิงก์ดาวน์โหลด', style: AppType.label),
          const SizedBox(height: AppSpacing.xs),
          Container(
            width: double.infinity,
            padding: const EdgeInsets.all(AppSpacing.md),
            decoration: BoxDecoration(
              color: AppColors.surface,
              borderRadius: BorderRadius.circular(AppRadius.sm),
              border: Border.all(color: AppColors.border),
            ),
            child: SelectableText(
              state.linkUrl!,
              key: const Key('export-url'),
              style: AppType.label.copyWith(color: AppColors.info),
            ),
          ),
          const SizedBox(height: AppSpacing.xs),
          Text(
            'คัดลอกลิงก์ไปวางในเบราว์เซอร์เพื่อดาวน์โหลดไฟล์',
            style: AppType.caption,
          ),
          const SizedBox(height: AppSpacing.sm),
          OutlinedButton.icon(
            key: const Key('export-copy'),
            onPressed: _copyLink,
            icon: const Icon(Icons.copy_outlined, size: AppSpacing.xl),
            label: const Text('คัดลอกลิงก์'),
          ),
          if (state.linkCopied)
            Text(
              'คัดลอกลิงก์แล้ว',
              key: const Key('export-copied'),
              style: AppType.caption.copyWith(color: AppColors.success),
            )
          else if (state.linkCopyFailed)
            Text(
              'คัดลอกอัตโนมัติไม่สำเร็จ กรุณาคัดลอกลิงก์ด้วยตนเอง',
              key: const Key('export-copy-failed'),
              style: AppType.caption.copyWith(color: AppColors.warning),
            ),
        ],
      ],
    );
  }

  Widget _pending(ExportState state, DataExportController controller) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            const Icon(
              Icons.schedule,
              size: AppSpacing.xl,
              color: AppColors.textSecondary,
            ),
            const SizedBox(width: AppSpacing.sm),
            Expanded(
              child: Text(
                state.pendingMessage,
                key: const Key('export-status'),
                style: AppType.bodySecondary,
              ),
            ),
          ],
        ),
        const SizedBox(height: AppSpacing.sm),
        Align(
          alignment: Alignment.centerLeft,
          child: TextButton(
            key: const Key('export-refresh'),
            onPressed: state.refreshing ? null : controller.refresh,
            child: Text(state.refreshing ? 'กำลังตรวจสอบ...' : 'ตรวจสอบสถานะ'),
          ),
        ),
        if (state.errorMessage != null)
          Text(
            state.errorMessage!,
            key: const Key('export-error'),
            style: AppType.caption.copyWith(color: AppColors.danger),
          ),
      ],
    );
  }

  Widget _idle(ExportState state, DataExportController controller) {
    final bool rateLimited = state.rateLimitedSeconds != null;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        if (state.expired)
          Text(
            'สำเนาข้อมูลนี้หมดอายุแล้ว กรุณาขอข้อมูลใหม่',
            key: const Key('export-expired'),
            style: AppType.caption,
          )
        else if (state.phase == ExportPhase.failed)
          Text(
            'การจัดเตรียมสำเนาข้อมูลไม่สำเร็จ กรุณาขอใหม่',
            key: const Key('export-failed'),
            style: AppType.caption,
          ),
        if (rateLimited) ...<Widget>[
          if (state.expired || state.phase == ExportPhase.failed)
            const SizedBox(height: AppSpacing.xs),
          Text(
            'สามารถขอข้อมูลใหม่ได้ในอีก '
            '${formatExportWait(state.rateLimitedSeconds!)}',
            key: const Key('export-rate-limit'),
            style: AppType.caption,
          ),
        ],
        if (state.errorMessage != null) ...<Widget>[
          const SizedBox(height: AppSpacing.xs),
          Text(
            state.errorMessage!,
            key: const Key('export-error'),
            style: AppType.caption.copyWith(color: AppColors.danger),
          ),
        ],
        const SizedBox(height: AppSpacing.md),
        SizedBox(
          width: double.infinity,
          child: FilledButton(
            key: const Key('export-request'),
            onPressed: state.requesting || rateLimited
                ? null
                : controller.requestExport,
            style: FilledButton.styleFrom(
              backgroundColor: AppColors.info,
              foregroundColor: AppColors.textOnAccent,
              minimumSize: const Size.fromHeight(AppSpacing.xxxl),
              textStyle: AppType.button,
            ),
            child: Text(
              state.requesting ? 'กำลังส่งคำขอ...' : state.requestLabel,
            ),
          ),
        ),
      ],
    );
  }

  Future<void> _copyLink() async {
    final provider = dataExportControllerProvider(widget.profileId);
    final String? url = ref.read(provider).linkUrl;

    if (url == null) {
      return;
    }

    final bool copied = await copyExportLink(url);

    if (!mounted) {
      return;
    }

    ref.read(provider.notifier).markLinkCopied(copied);
  }
}

/// `403`: an explanation, not an error banner. Deliberately offers no action:
/// retrying cannot change a permission decision (`.ai/MOBILE_DESIGN.md` §5).
class _PermissionNotice extends StatelessWidget {
  const _PermissionNotice();

  @override
  Widget build(BuildContext context) {
    return Container(
      key: const Key('export-permission'),
      width: double.infinity,
      padding: const EdgeInsets.all(AppSpacing.md),
      decoration: BoxDecoration(
        color: AppColors.infoSurface,
        borderRadius: BorderRadius.circular(AppRadius.sm),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Text(
            'สิทธิ์ของคุณยังไม่รวมการขอข้อมูล',
            style: AppType.label.copyWith(fontWeight: FontWeight.w600),
          ),
          const SizedBox(height: AppSpacing.xs),
          Text(
            'หากต้องการสำเนาข้อมูล กรุณาติดต่อเจ้าของครอบครัว '
            'หรือผู้ดูแลพื้นที่ทำงานเพื่อขอสิทธิ์',
            style: AppType.caption,
          ),
        ],
      ),
    );
  }
}

class _Metric extends StatelessWidget {
  const _Metric({
    required this.label,
    required this.valueKey,
    required this.value,
  });

  final String label;
  final Key valueKey;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        Text(label, style: AppType.caption),
        const SizedBox(height: AppSpacing.xs),
        Text(
          value,
          key: valueKey,
          style: AppType.label.copyWith(
            fontWeight: FontWeight.w600,
            fontFeatures: const <FontFeature>[FontFeature.tabularFigures()],
          ),
        ),
      ],
    );
  }
}

/// `1.4 MB` / `820 ไบต์` / `ไม่ทราบขนาด`. No `intl` dependency.
String formatExportByteSize(int? bytes) {
  if (bytes == null || bytes < 0) {
    return 'ไม่ทราบขนาด';
  }

  if (bytes < 1024) {
    return '$bytes ไบต์';
  }

  final double kilobytes = bytes / 1024;

  if (kilobytes < 1024) {
    return '${_oneDecimal(kilobytes)} KB';
  }

  return '${_oneDecimal(kilobytes / 1024)} MB';
}

/// `24 ต.ค. 2569 เวลา 09:00 น.` via the shared Thai formatter.
String formatExportExpiry(String? expiresAt) {
  if (expiresAt == null || expiresAt.isEmpty) {
    return 'ไม่ทราบ';
  }

  final String formatted = formatThaiDateTime(expiresAt);

  return formatted.isEmpty ? 'ไม่ทราบ' : formatted;
}

/// `1 ชั่วโมง 30 นาที` — the same wording the web card uses.
String formatExportWait(int seconds) {
  final int totalMinutes = seconds <= 0 ? 1 : (seconds / 60).ceil();
  final int hours = totalMinutes ~/ 60;
  final int minutes = totalMinutes % 60;

  if (hours > 0 && minutes > 0) {
    return '$hours ชั่วโมง $minutes นาที';
  }

  if (hours > 0) {
    return '$hours ชั่วโมง';
  }

  return '$minutes นาที';
}

String _oneDecimal(double value) {
  final String fixed = value.toStringAsFixed(1);

  return fixed.endsWith('.0') ? fixed.substring(0, fixed.length - 2) : fixed;
}
