import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/network/api_failure.dart';
import '../../core/workspace/current_workspace.dart';
import 'appointment_models.dart';
import 'appointments_repository.dart';

/// The result of a create/update/cancel call, already mapped to what the UI
/// must do. The controller never throws a server failure into a widget.
sealed class AppointmentWriteOutcome {
  const AppointmentWriteOutcome();
}

final class AppointmentWriteSuccess extends AppointmentWriteOutcome {
  const AppointmentWriteSuccess(this.appointment);

  final Appointment appointment;
}

/// `412`: the row changed on another device. The caller must reload and show
/// fresh values instead of retrying the stale write (AP-007).
final class StaleAppointment extends AppointmentWriteOutcome {
  const StaleAppointment([this.message = messageText]);

  static const String messageText = 'นัดหมายถูกแก้ไขจากอุปกรณ์อื่น';

  final String message;
}

/// `422`: sentence per field, so the sheet renders it under that field.
final class AppointmentValidationFailure extends AppointmentWriteOutcome {
  const AppointmentValidationFailure({
    required this.fieldErrors,
    required this.message,
  });

  final Map<String, String> fieldErrors;
  final String message;
}

/// `409`: the lifecycle no longer allows the action (for example, a visit
/// already started).
final class AppointmentConflict extends AppointmentWriteOutcome {
  const AppointmentConflict(this.message);

  final String message;
}

/// Transport, authorization, or any other failure with a safe Thai sentence.
final class AppointmentWriteFailure extends AppointmentWriteOutcome {
  const AppointmentWriteFailure([this.message = ApiFailure.genericMessage]);

  final String message;
}

/// Loads the current profile's appointments, upcoming first, and applies the
/// create/update/cancel writes one at a time.
class AppointmentsController extends AsyncNotifier<AppointmentsData> {
  @override
  Future<AppointmentsData> build() async {
    final AppointmentsRepository repository = ref.watch(
      appointmentsRepositoryProvider,
    );
    final WorkspaceContext context = await ref.watch(
      currentWorkspaceProvider.future,
    );

    if (!context.hasWorkspace || !context.hasProfile) {
      return AppointmentsData.noProfile;
    }

    return AppointmentsData(
      hasProfile: true,
      canManage: true,
      appointments: await repository.list(context),
    );
  }

  void retry() {
    ref.invalidateSelf();
  }

  /// Re-reads the list, keeping the current data visible if the refetch fails.
  /// The stale sheet catches the failure and stays on screen.
  Future<void> refresh() async {
    final AppointmentsRepository repository = ref.read(
      appointmentsRepositoryProvider,
    );
    final WorkspaceContext context = await ref.read(
      currentWorkspaceProvider.future,
    );

    if (!context.hasWorkspace || !context.hasProfile) {
      state = const AsyncData<AppointmentsData>(AppointmentsData.noProfile);
      return;
    }

    final List<Appointment> appointments = await repository.list(context);

    state = AsyncData<AppointmentsData>(
      AppointmentsData(
        hasProfile: true,
        canManage: true,
        appointments: appointments,
      ),
    );
  }

  Appointment? appointmentById(String id) => state.value?.byId(id);

  Future<AppointmentWriteOutcome> create({
    required String title,
    required DateTime startsAt,
    required int durationMinutes,
    String? location,
    String? clinician,
    String? notes,
  }) async {
    try {
      final WorkspaceContext context = await ref.read(
        currentWorkspaceProvider.future,
      );
      final Appointment created = await ref
          .read(appointmentsRepositoryProvider)
          .create(
            context,
            profileId: context.profileId,
            title: title,
            startsAt: startsAt,
            durationMinutes: durationMinutes,
            location: location,
            clinician: clinician,
            notes: notes,
          );

      _apply(created);
      await _refreshAfterWrite();

      return AppointmentWriteSuccess(created);
    } on ApiFailure catch (failure) {
      return _outcomeFor(failure);
    } on Object {
      return const AppointmentWriteFailure();
    }
  }

  /// Sends only fields whose value actually differs from [original]. The
  /// server's current version travels in `If-Match`, so an out-of-band edit is
  /// answered with `412` rather than overwritten.
  ///
  /// Named `updateAppointment` because [AsyncNotifier.update] already owns
  /// `update` (it is the async-state modifier); this is the domain action.
  Future<AppointmentWriteOutcome> updateAppointment(
    Appointment original, {
    required String title,
    required DateTime startsAt,
    required int durationMinutes,
    String? location,
    String? clinician,
    String? notes,
  }) async {
    final String? titleChange = _changedText(original.title, title);
    final DateTime? startsAtChange =
        _sameInstant(original.scheduledAtDate, startsAt) ? null : startsAt;
    final int? durationChange = original.durationMinutes == durationMinutes
        ? null
        : durationMinutes;
    final String? locationChange = _changedText(original.location, location);
    final String? clinicianChange = _changedText(
      original.providerName,
      clinician,
    );
    final String? notesChange = _changedText(original.notes, notes);

    if (titleChange == null &&
        startsAtChange == null &&
        durationChange == null &&
        locationChange == null &&
        clinicianChange == null &&
        notesChange == null) {
      return AppointmentWriteSuccess(original);
    }

    try {
      final WorkspaceContext context = await ref.read(
        currentWorkspaceProvider.future,
      );
      final Appointment updated = await ref
          .read(appointmentsRepositoryProvider)
          .update(
            context,
            original.id,
            version: original.version,
            title: titleChange,
            startsAt: startsAtChange,
            durationMinutes: durationChange,
            location: locationChange,
            clinician: clinicianChange,
            notes: notesChange,
          );

      _apply(updated);
      await _refreshAfterWrite();

      return AppointmentWriteSuccess(updated);
    } on ApiFailure catch (failure) {
      return _outcomeFor(failure);
    } on Object {
      return const AppointmentWriteFailure();
    }
  }

  Future<AppointmentWriteOutcome> cancel(Appointment appointment) async {
    try {
      final WorkspaceContext context = await ref.read(
        currentWorkspaceProvider.future,
      );
      final Appointment cancelled = await ref
          .read(appointmentsRepositoryProvider)
          .cancel(context, appointment.id, appointment.version);

      _apply(cancelled);
      await _refreshAfterWrite();

      return AppointmentWriteSuccess(cancelled);
    } on ApiFailure catch (failure) {
      return _outcomeFor(failure);
    } on Object {
      return const AppointmentWriteFailure();
    }
  }

  void _apply(Appointment updated) {
    final AppointmentsData? current = state.value;

    if (current == null) {
      return;
    }

    final List<Appointment> next = List<Appointment>.of(current.appointments);
    final int index = next.indexWhere(
      (Appointment appointment) => appointment.id == updated.id,
    );

    if (index >= 0) {
      next[index] = updated;
    } else {
      next.add(updated);
    }

    state = AsyncData<AppointmentsData>(
      AppointmentsData(
        hasProfile: current.hasProfile,
        canManage: current.canManage,
        appointments: next,
      ),
    );
  }

  /// Re-reads the ordered list after a successful write. The row returned by
  /// the write is already visible, so a failed refetch keeps that row instead
  /// of turning a successful save into an error state.
  Future<void> _refreshAfterWrite() async {
    try {
      final AppointmentsRepository repository = ref.read(
        appointmentsRepositoryProvider,
      );
      final WorkspaceContext context = await ref.read(
        currentWorkspaceProvider.future,
      );

      if (!context.hasWorkspace || !context.hasProfile) {
        return;
      }

      final List<Appointment> appointments = await repository.list(context);

      state = AsyncData<AppointmentsData>(
        AppointmentsData(
          hasProfile: true,
          canManage: true,
          appointments: appointments,
        ),
      );
    } on Object {
      // Keep the optimistic state; the write already succeeded.
    }
  }

  AppointmentWriteOutcome _outcomeFor(ApiFailure failure) {
    if (failure.status == 412) {
      return const StaleAppointment();
    }

    if (failure.status == 422) {
      return AppointmentValidationFailure(
        fieldErrors: failure.fieldErrors,
        message: failure.message,
      );
    }

    if (failure.status == 409) {
      return AppointmentConflict(failure.message);
    }

    return AppointmentWriteFailure(failure.message);
  }

  static String? _changedText(String? original, String? next) {
    final String current = (original ?? '').trim();
    final String candidate = (next ?? '').trim();

    return current == candidate ? null : candidate;
  }

  static bool _sameInstant(DateTime? original, DateTime next) {
    return original != null && original.isAtSameMomentAs(next);
  }
}

final AsyncNotifierProvider<AppointmentsController, AppointmentsData>
appointmentsControllerProvider =
    AsyncNotifierProvider<AppointmentsController, AppointmentsData>(
      AppointmentsController.new,
      isAutoDispose: true,
      retry: (int retryCount, Object error) => null,
    );
