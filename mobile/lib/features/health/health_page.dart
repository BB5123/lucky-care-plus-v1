import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/ai/ai_models.dart';
import '../../../core/network/api_failure.dart';
import '../../../design_system/state_views.dart';
import '../../../design_system/tokens.dart';
import 'health_controller.dart';
import 'health_models.dart';
import 'health_repository.dart';
import 'ai_view.dart';
import 'widgets/measurement_tile.dart';
import 'widgets/medication_view.dart';
import 'widgets/ocr_scan_sheet.dart';
import 'widgets/record_measurement_sheet.dart';

/// The sections inside the health tab. Measurements stay the default so the
/// existing reading loop is unchanged; the AI assistant is an explicit third
/// section so the summary/knowledge calls only run when the user opens it.
enum HealthSection { measurements, medication, ai }

/// `/health` — readings and medication for the current profile.
class HealthPage extends ConsumerStatefulWidget {
  const HealthPage({super.key});

  static const String routePath = '/health';

  @override
  ConsumerState<HealthPage> createState() => _HealthPageState();
}

class _HealthPageState extends ConsumerState<HealthPage> {
  HealthSection _section = HealthSection.measurements;

  /// Drives the automatic "load the next page" trigger at the end of the
  /// history list. The explicit button stays the primary affordance; this is
  /// only a convenience for a thumb already at the bottom.
  final ScrollController _scrollController = ScrollController();

  @override
  void initState() {
    super.initState();
    _scrollController.addListener(_handleScroll);
  }

  @override
  void dispose() {
    _scrollController
      ..removeListener(_handleScroll)
      ..dispose();
    super.dispose();
  }

  void _handleScroll() {
    if (!_scrollController.hasClients) {
      return;
    }

    if (_scrollController.position.extentAfter > AppSpacing.xxl) {
      return;
    }

    final HealthData? data = ref.read(healthControllerProvider).value;

    if (data == null ||
        !data.hasMore ||
        data.isLoadingMore ||
        data.loadMoreError != null) {
      return;
    }

    unawaited(ref.read(healthControllerProvider.notifier).loadMore());
  }

  @override
  Widget build(BuildContext context) {
    final AsyncValue<HealthData> state = ref.watch(healthControllerProvider);
    final HealthData? data = state.value;

    return Scaffold(
      key: const Key('health-page'),
      floatingActionButton:
          _section == HealthSection.measurements &&
              data != null &&
              data.hasProfile
          ? FloatingActionButton.extended(
              key: const Key('health-record-button'),
              onPressed: () => RecordMeasurementSheet.show(context),
              backgroundColor: AppColors.info,
              foregroundColor: AppColors.textOnAccent,
              icon: const Icon(Icons.add),
              label: const Text('บันทึกค่า', style: AppType.button),
            )
          : null,
      body: Column(
        children: <Widget>[
          Padding(
            padding: const EdgeInsets.fromLTRB(
              AppSpacing.lg,
              AppSpacing.lg,
              AppSpacing.lg,
              AppSpacing.sm,
            ),
            child: SegmentedButton<HealthSection>(
              key: const Key('health-section-segments'),
              showSelectedIcon: false,
              style: SegmentedButton.styleFrom(
                visualDensity: VisualDensity.compact,
              ),
              segments: const <ButtonSegment<HealthSection>>[
                ButtonSegment<HealthSection>(
                  value: HealthSection.measurements,
                  label: Text(
                    'ค่าวัด',
                    key: Key('health-section-measurements'),
                  ),
                ),
                ButtonSegment<HealthSection>(
                  value: HealthSection.medication,
                  label: Text('ยา', key: Key('health-section-medication')),
                ),
                ButtonSegment<HealthSection>(
                  value: HealthSection.ai,
                  label: Text(
                    'ผู้ช่วย AI',
                    key: Key('health-section-ai'),
                  ),
                ),
              ],
              selected: <HealthSection>{_section},
              onSelectionChanged: (Set<HealthSection> selection) {
                setState(() {
                  _section = selection.first;
                });
              },
            ),
          ),
          Expanded(
            child: switch (_section) {
              HealthSection.measurements => _measurements(state),
              HealthSection.medication => const MedicationView(),
              HealthSection.ai => const AiView(),
            },
          ),
        ],
      ),
    );
  }

  Widget _measurements(AsyncValue<HealthData> state) {
    return state.when(
      loading: () => const LoadingState(key: Key('health-loading')),
      error: (Object error, StackTrace stackTrace) {
        if (error is ApiFailure && error.isPermissionDenied) {
          return const EmptyState(
            key: Key('health-permission-state'),
            icon: Icons.lock_outline,
            title: 'คุณยังไม่มีสิทธิ์เข้าถึงข้อมูลนี้',
            message: 'หากต้องการดูค่าสุขภาพ กรุณาขอสิทธิ์จากเจ้าของข้อมูล',
          );
        }

        return ErrorState(
          key: const Key('health-error'),
          message: _errorMessage(error),
          onRetry: () {
            ref.read(healthControllerProvider.notifier).retry();
          },
        );
      },
      data: (HealthData value) {
        if (!value.hasProfile) {
          return const EmptyState(
            key: Key('health-no-profile'),
            icon: Icons.monitor_heart_outlined,
            title: 'ยังไม่มีโปรไฟล์สุขภาพ',
            message: 'เมื่อมีความเชื่อมโยงกับโปรไฟล์สุขภาพ ค่าที่บันทึกไว้จะแสดงที่นี่',
          );
        }

        void loadMore() {
          unawaited(ref.read(healthControllerProvider.notifier).loadMore());
        }

        final Widget content = value.isEmpty
            ? const EmptyState(
                key: Key('health-empty-state'),
                icon: Icons.monitor_heart_outlined,
                title: 'ยังไม่มีค่าที่บันทึกไว้',
                message: 'กด “บันทึกค่า” เพื่อเริ่มบันทึกความดัน น้ำตาล หรือน้ำหนัก',
              )
            : ListView.separated(
                key: const Key('health-list'),
                controller: _scrollController,
                padding: const EdgeInsets.fromLTRB(
                  AppSpacing.lg,
                  AppSpacing.lg,
                  AppSpacing.lg,
                  AppSpacing.xxxl * 2,
                ),
                itemCount: value.measurements.length + 1,
                separatorBuilder: (BuildContext context, int index) =>
                    const SizedBox(height: AppSpacing.md),
                itemBuilder: (BuildContext context, int index) {
                  if (index == value.measurements.length) {
                    return _HistoryFooter(data: value, onLoadMore: loadMore);
                  }

                  final HealthMeasurement measurement =
                      value.measurements[index];

                  return MeasurementTile(
                    key: Key('measurement-${measurement.id}'),
                    measurement: measurement,
                  );
                },
              );

        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            Padding(
              padding: const EdgeInsets.fromLTRB(
                AppSpacing.lg,
                AppSpacing.lg,
                AppSpacing.lg,
                0,
              ),
              child: OutlinedButton.icon(
                key: const Key('health-scan-device'),
                onPressed: () => OcrScanSheet.show(
                  context,
                  AiExtractionKind.deviceReading,
                ),
                icon: const Icon(Icons.document_scanner_outlined),
                label: const Text('สแกนหน้าจอเครื่องวัด'),
              ),
            ),
            Expanded(child: content),
          ],
        );
      },
    );
  }
}

/// Safe, user-facing sentence for a full-page or footer failure.
String _errorMessage(Object error) {
  if (error is ApiFailure) {
    return error.message;
  }

  if (error is PaginationContractException) {
    return error.message;
  }

  return ApiFailure.genericMessage;
}

/// The history footer.
///
/// It is always present at the end of the list and reserves a fixed height in
/// every state, so loading the next page never shifts the rows above it.
class _HistoryFooter extends StatelessWidget {
  const _HistoryFooter({required this.data, required this.onLoadMore});

  /// `AppSpacing.xxxl * 2` — enough for a caption plus a compact text button.
  static const double reservedHeight = AppSpacing.xxxl * 2;

  final HealthData data;
  final VoidCallback onLoadMore;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: reservedHeight,
      child: Center(
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: AppSpacing.lg),
          child: _content(),
        ),
      ),
    );
  }

  Widget _content() {
    if (data.isLoadingMore) {
      return Row(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          const SizedBox(
            width: AppSpacing.lg,
            height: AppSpacing.lg,
            child: CircularProgressIndicator(
              key: Key('health-load-more-progress'),
              strokeWidth: 2,
              color: AppColors.info,
            ),
          ),
          const SizedBox(width: AppSpacing.sm),
          Text('กำลังโหลด…', style: AppType.caption),
        ],
      );
    }

    final Object? error = data.loadMoreError;

    if (error != null) {
      return Column(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Text(
            _errorMessage(error),
            key: const Key('health-load-more-error'),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            textAlign: TextAlign.center,
            style: AppType.caption,
          ),
          TextButton(
            key: const Key('health-load-more-retry'),
            onPressed: onLoadMore,
            style: _linkStyle(),
            child: const Text('ลองใหม่'),
          ),
        ],
      );
    }

    if (!data.hasMore) {
      return Text(
        'แสดงครบทุกรายการแล้ว',
        key: const Key('health-history-end'),
        textAlign: TextAlign.center,
        style: AppType.caption,
      );
    }

    return Column(
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Text(
          'แสดง ${data.measurements.length} รายการ — เลื่อนเพื่อดูเพิ่ม',
          key: const Key('health-load-more-count'),
          textAlign: TextAlign.center,
          style: AppType.caption,
        ),
        TextButton(
          key: const Key('health-load-more'),
          onPressed: onLoadMore,
          style: _linkStyle(),
          child: const Text('โหลดเพิ่ม'),
        ),
      ],
    );
  }

  ButtonStyle _linkStyle() {
    return TextButton.styleFrom(
      foregroundColor: AppColors.info,
      textStyle: AppType.label,
      visualDensity: VisualDensity.compact,
      tapTargetSize: MaterialTapTargetSize.shrinkWrap,
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.lg,
        vertical: AppSpacing.xs,
      ),
      minimumSize: Size.zero,
    );
  }
}
