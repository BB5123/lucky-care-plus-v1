import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../design_system/tokens.dart';
import '../family_controller.dart';
import '../family_models.dart';
import '../invite_clipboard.dart';

/// Bottom sheet that issues one invitation.
///
/// The email is validated before the call. A `422` puts the server sentence
/// under the field it names, a `403` shows the calm permission line without
/// clearing what the user typed, and a `404`/`409` uses one neutral sentence so
/// the sheet never reveals whether an address is already a member.
class FamilyInviteSheet extends ConsumerStatefulWidget {
  const FamilyInviteSheet({super.key});

  static Future<void> show(BuildContext context) async {
    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      backgroundColor: AppColors.surfaceRaised,
      builder: (BuildContext context) => const FamilyInviteSheet(),
    );
  }

  @override
  ConsumerState<FamilyInviteSheet> createState() => _FamilyInviteSheetState();
}

class _FamilyInviteSheetState extends ConsumerState<FamilyInviteSheet> {
  final GlobalKey<FormState> _formKey = GlobalKey<FormState>();
  final TextEditingController _email = TextEditingController();

  String? _role;
  String? _roleError;
  Map<String, String> _serverErrors = const <String, String>{};
  String? _formError;
  bool _forbidden = false;
  bool _saving = false;
  bool _copied = false;
  bool _copyFailed = false;
  FamilyInvitation? _result;

  @override
  void dispose() {
    _email.dispose();
    super.dispose();
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

  String? _validateEmail(String? raw) {
    final String? server = _serverErrors['email'];

    if (server != null) {
      return server;
    }

    final String value = (raw ?? '').trim();

    if (value.isEmpty) {
      return 'กรุณากรอกอีเมลของผู้รับ';
    }

    if (!isValidInviteEmail(value)) {
      return 'กรุณากรอกอีเมลให้ถูกต้อง';
    }

    return null;
  }

  Future<void> _submit() async {
    setState(() {
      _serverErrors = const <String, String>{};
      _formError = null;
      _forbidden = false;
      _roleError = null;
      _copied = false;
      _copyFailed = false;
    });

    final bool formValid = _formKey.currentState?.validate() ?? false;
    final String? role = _role;

    if (role == null) {
      setState(() => _roleError = 'กรุณาเลือกบทบาท');
    }

    if (!formValid || role == null) {
      return;
    }

    setState(() => _saving = true);

    final InviteOutcome outcome = await ref
        .read(familyControllerProvider.notifier)
        .invite(email: _email.text.trim(), role: role);

    if (!mounted) {
      return;
    }

    switch (outcome) {
      case InviteSuccess(:final FamilyInvitation invitation):
        setState(() {
          _saving = false;
          _result = invitation;
        });
      case InviteValidationFailure(
        :final Map<String, String> fieldErrors,
        :final String message,
      ):
        _showValidation(fieldErrors, message);
      case InviteForbidden():
        setState(() {
          _saving = false;
          _forbidden = true;
        });
      case InviteUnavailable(:final String message):
        setState(() {
          _saving = false;
          _formError = message;
        });
      case InviteFailure(:final String message):
        setState(() {
          _saving = false;
          _formError = message;
        });
    }
  }

  void _showValidation(Map<String, String> errors, String message) {
    String? unknown;

    for (final MapEntry<String, String> entry in errors.entries) {
      if (entry.key == 'email' || entry.key == 'role') {
        continue;
      }

      unknown = entry.value;
      break;
    }

    setState(() {
      _saving = false;
      _serverErrors = errors;
      _roleError = errors['role'];
      _formError = unknown ?? (errors.isEmpty ? message : null);
    });

    // Re-run the validators so the server sentence lands under the email field
    // instead of in a form-level banner.
    _formKey.currentState?.validate();
  }

  Future<void> _copyLink() async {
    final FamilyInvitation? invitation = _result;

    if (invitation == null) {
      return;
    }

    final bool copied = await copyInvitationLink(invitation.link);

    if (!mounted) {
      return;
    }

    setState(() {
      _copied = copied;
      _copyFailed = !copied;
    });
  }

  @override
  Widget build(BuildContext context) {
    final FamilyInvitation? result = _result;

    return Padding(
      key: const Key('family-invite-sheet'),
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: SingleChildScrollView(
        padding: const EdgeInsets.all(AppSpacing.lg),
        child: result == null ? _formView() : _successView(result),
      ),
    );
  }

  Widget _formView() {
    final InvitableRoleOption? selected = _role == null
        ? null
        : invitableRoleOption(_role!);

    return Form(
      key: _formKey,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Row(
            children: <Widget>[
              Expanded(child: Text('เชิญสมาชิก', style: AppType.headline)),
              IconButton(
                key: const Key('family-invite-close'),
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
          const SizedBox(height: AppSpacing.xs),
          const Text(
            'ผู้รับจะเข้าร่วมครอบครัวได้จากลิงก์เชิญเท่านั้น',
            style: AppType.caption,
          ),
          const SizedBox(height: AppSpacing.lg),
          TextFormField(
            key: const Key('family-invite-email'),
            controller: _email,
            keyboardType: TextInputType.emailAddress,
            textInputAction: TextInputAction.done,
            autocorrect: false,
            enableSuggestions: false,
            decoration: const InputDecoration(
              labelText: 'อีเมลของผู้รับ',
              border: OutlineInputBorder(),
              errorMaxLines: 3,
            ),
            onChanged: (String value) => _clearServerError('email'),
            validator: _validateEmail,
          ),
          const SizedBox(height: AppSpacing.lg),
          Text('บทบาท', style: AppType.sectionTitle),
          const SizedBox(height: AppSpacing.sm),
          Wrap(
            spacing: AppSpacing.sm,
            runSpacing: AppSpacing.sm,
            children: <Widget>[
              for (final InvitableRoleOption option in invitableRoleOptions)
                ChoiceChip(
                  key: Key('family-invite-role-${option.role}'),
                  label: Text(option.label),
                  selected: _role == option.role,
                  onSelected: _saving
                      ? null
                      : (bool selected) {
                          setState(() {
                            _role = selected ? option.role : null;
                            _roleError = null;
                          });
                          _clearServerError('role');
                        },
                ),
            ],
          ),
          if (_roleError != null) ...<Widget>[
            const SizedBox(height: AppSpacing.xs),
            Text(
              _roleError!,
              key: const Key('family-invite-role-error'),
              style: AppType.caption.copyWith(color: AppColors.danger),
            ),
          ],
          if (selected != null) ...<Widget>[
            const SizedBox(height: AppSpacing.sm),
            Text(
              selected.description,
              key: const Key('family-invite-role-description'),
              style: AppType.caption,
            ),
          ],
          if (_forbidden) ...<Widget>[
            const SizedBox(height: AppSpacing.lg),
            _notice(
              key: const Key('family-invite-forbidden'),
              background: AppColors.infoSurface,
              foreground: AppColors.info,
              message: InviteForbidden.messageText,
              detail: 'หากต้องการเชิญสมาชิก กรุณาขอสิทธิ์จากเจ้าของข้อมูล',
            ),
          ],
          if (_formError != null) ...<Widget>[
            const SizedBox(height: AppSpacing.lg),
            _notice(
              key: const Key('family-invite-error'),
              background: AppColors.dangerSurface,
              foreground: AppColors.danger,
              message: _formError!,
            ),
          ],
          const SizedBox(height: AppSpacing.lg),
          FilledButton(
            key: const Key('family-invite-submit'),
            onPressed: _saving ? null : _submit,
            style: FilledButton.styleFrom(
              backgroundColor: AppColors.info,
              foregroundColor: AppColors.textOnAccent,
              minimumSize: const Size.fromHeight(AppSpacing.xxxl),
              textStyle: AppType.button,
            ),
            child: Text(_saving ? 'กำลังส่งคำเชิญ…' : 'ส่งคำเชิญ'),
          ),
        ],
      ),
    );
  }

  Widget _successView(FamilyInvitation invitation) {
    return Column(
      key: const Key('family-invite-success'),
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Row(
          children: <Widget>[
            Expanded(child: Text('ส่งคำเชิญแล้ว', style: AppType.headline)),
            IconButton(
              key: const Key('family-invite-close'),
              tooltip: 'ปิด',
              onPressed: () {
                Navigator.of(context).pop();
              },
              icon: const Icon(Icons.close),
            ),
          ],
        ),
        const SizedBox(height: AppSpacing.xs),
        if (invitation.email.isNotEmpty)
          Text(invitation.email, style: AppType.title),
        const SizedBox(height: AppSpacing.xs),
        Text(invitation.roleLabel, style: AppType.caption),
        const SizedBox(height: AppSpacing.lg),
        Text(
          invitation.expiryLabel,
          key: const Key('family-invite-expiry'),
          style: AppType.body,
        ),
        const SizedBox(height: AppSpacing.sm),
        const Text(
          'ลิงก์เชิญเป็นช่องทางเดียวที่ผู้รับจะเข้าร่วมครอบครัวได้ '
          'กรุณาส่งต่อให้ผู้รับด้วยตนเอง',
          style: AppType.caption,
        ),
        const SizedBox(height: AppSpacing.lg),
        if (invitation.hasLink)
          FilledButton.icon(
            key: const Key('family-invite-copy'),
            onPressed: _copyLink,
            style: FilledButton.styleFrom(
              backgroundColor: AppColors.info,
              foregroundColor: AppColors.textOnAccent,
              minimumSize: const Size.fromHeight(AppSpacing.xxxl),
              textStyle: AppType.button,
            ),
            icon: const Icon(Icons.copy_outlined),
            label: const Text('คัดลอกลิงก์เชิญ'),
          )
        else
          const Text(
            'ไม่พบลิงก์เชิญในคำตอบของเซิร์ฟเวอร์',
            key: Key('family-invite-no-link'),
            style: AppType.caption,
          ),
        if (_copied) ...<Widget>[
          const SizedBox(height: AppSpacing.sm),
          Text(
            'คัดลอกลิงก์แล้ว',
            key: const Key('family-invite-copied'),
            style: AppType.caption.copyWith(color: AppColors.success),
          ),
        ],
        if (_copyFailed) ...<Widget>[
          const SizedBox(height: AppSpacing.sm),
          Text(
            'คัดลอกอัตโนมัติไม่สำเร็จ กรุณาคัดลอกลิงก์ด้วยตนเอง',
            key: const Key('family-invite-copy-failed'),
            style: AppType.caption.copyWith(color: AppColors.danger),
          ),
        ],
        const SizedBox(height: AppSpacing.md),
        TextButton(
          key: const Key('family-invite-done'),
          onPressed: () {
            Navigator.of(context).pop();
          },
          child: const Text('เสร็จสิ้น'),
        ),
      ],
    );
  }

  static Widget _notice({
    required Key key,
    required Color background,
    required Color foreground,
    required String message,
    String? detail,
  }) {
    return Container(
      key: key,
      width: double.infinity,
      padding: const EdgeInsets.all(AppSpacing.md),
      decoration: BoxDecoration(
        color: background,
        borderRadius: BorderRadius.circular(AppRadius.md),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Text(
            message,
            style: AppType.label.copyWith(color: foreground),
          ),
          if (detail != null) ...<Widget>[
            const SizedBox(height: AppSpacing.xs),
            Text(detail, style: AppType.caption),
          ],
        ],
      ),
    );
  }
}
