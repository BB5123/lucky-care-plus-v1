import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/medication/medication_models.dart';
import '../../core/medication/medication_repository.dart';
import '../../core/workspace/current_workspace.dart';

/// Loads the current profile's medication plans and seven-day adherence.
class MedicationController extends AsyncNotifier<MedicationData> {
  @override
  Future<MedicationData> build() async {
    final MedicationRepository repository = ref.watch(
      medicationRepositoryProvider,
    );
    final WorkspaceContext context = await ref.watch(
      currentWorkspaceProvider.future,
    );

    if (!context.hasProfile) {
      return MedicationData.noProfile;
    }

    final Future<List<MedicationPlan>> plans = repository.listPlans(context);
    final Future<List<MedicationAdherence>> adherence = repository.adherence(
      context,
    );

    return MedicationData(
      hasProfile: true,
      plans: await plans,
      adherence: await adherence,
    );
  }

  void retry() {
    ref.invalidateSelf();
  }

  /// Saves a plan through the normal medication endpoint and prepends it to the
  /// current list. Used by the OCR review sheet after a human confirms a draft:
  /// the AI itself never writes (AIG-007).
  Future<void> createPlan(CreateMedicationPlanInput input) async {
    final WorkspaceContext context = await ref.read(
      currentWorkspaceProvider.future,
    );
    final MedicationRepository repository = ref.read(
      medicationRepositoryProvider,
    );
    final MedicationPlan created = await repository.createPlan(context, input);
    final MedicationData? current = state.value;

    state = AsyncData<MedicationData>(
      MedicationData(
        hasProfile: current?.hasProfile ?? true,
        plans: <MedicationPlan>[created, ...?current?.plans],
        adherence: current?.adherence ?? const <MedicationAdherence>[],
      ),
    );
  }

  /// Reports one dose. A skipped dose without a note never reaches the
  /// repository: that is the same rule the server enforces.
  Future<void> recordDose(
    MedicationPlan plan, {
    required MedicationDoseStatus status,
    String? note,
    String? scheduledFor,
  }) async {
    final String trimmedNote = (note ?? '').trim();

    if (status == MedicationDoseStatus.skipped && trimmedNote.isEmpty) {
      return;
    }

    final WorkspaceContext context = await ref.read(
      currentWorkspaceProvider.future,
    );
    final MedicationRepository repository = ref.read(
      medicationRepositoryProvider,
    );

    await repository.recordDose(
      context,
      planId: plan.id,
      status: status.apiValue,
      note: trimmedNote.isEmpty ? null : trimmedNote,
      scheduledFor: scheduledFor,
    );
  }
}

final AsyncNotifierProvider<MedicationController, MedicationData>
medicationControllerProvider =
    AsyncNotifierProvider<MedicationController, MedicationData>(
      MedicationController.new,
      isAutoDispose: true,
      retry: (int retryCount, Object error) => null,
    );
