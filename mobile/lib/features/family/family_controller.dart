import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/workspace/current_workspace.dart';
import 'family_models.dart';
import 'family_repository.dart';

/// Loads the workspace member list.
class FamilyController extends AsyncNotifier<FamilyData> {
  @override
  Future<FamilyData> build() async {
    final FamilyRepository repository = ref.watch(familyRepositoryProvider);
    final WorkspaceContext context = await ref.watch(
      currentWorkspaceProvider.future,
    );

    if (!context.hasWorkspace) {
      return FamilyData.noWorkspace;
    }

    return FamilyData(
      hasWorkspace: true,
      members: await repository.list(context),
    );
  }

  void retry() {
    ref.invalidateSelf();
  }
}

final AsyncNotifierProvider<FamilyController, FamilyData>
familyControllerProvider = AsyncNotifierProvider<FamilyController, FamilyData>(
  FamilyController.new,
  isAutoDispose: true,
  retry: (int retryCount, Object error) => null,
);
