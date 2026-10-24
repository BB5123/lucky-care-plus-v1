import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/auth/auth_controller.dart';
import '../../core/auth/auth_state.dart';
import '../../core/auth/auth_validation.dart';
import '../../core/network/api_failure.dart';
import '../../design_system/tokens.dart';
import 'register_page.dart';
import 'widgets/auth_shell.dart';

/// `/login` — email + password. On success the token is stored by the
/// controller and the router redirects to the dashboard.
class LoginPage extends ConsumerStatefulWidget {
  const LoginPage({super.key});

  static const String routePath = '/login';

  @override
  ConsumerState<LoginPage> createState() => _LoginPageState();
}

class _LoginPageState extends ConsumerState<LoginPage> {
  final GlobalKey<FormState> _formKey = GlobalKey<FormState>();
  final TextEditingController _email = TextEditingController();
  final TextEditingController _password = TextEditingController();

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  String? _validateEmail(String? value) {
    final String email = (value ?? '').trim();

    if (email.isEmpty) {
      return 'กรุณากรอกอีเมล';
    }

    if (!AuthValidator.isValidEmail(email)) {
      return 'รูปแบบอีเมลไม่ถูกต้อง';
    }

    return null;
  }

  String? _validatePassword(String? value) {
    final String password = value ?? '';

    if (password.isEmpty) {
      return 'กรุณากรอกรหัสผ่าน';
    }

    if (!AuthValidator.isValidPassword(password)) {
      return 'รหัสผ่านต้องมีอย่างน้อย 10 ตัวอักษร';
    }

    return null;
  }

  Future<void> _submit() async {
    final AuthState auth = ref.read(authControllerProvider);

    if (auth.isLoading) {
      return;
    }

    final FormState? form = _formKey.currentState;

    // D-T05: an invalid form never reaches the repository.
    if (form == null || !form.validate()) {
      return;
    }

    await ref
        .read(authControllerProvider.notifier)
        .signIn(email: _email.text.trim(), password: _password.text);
    // Success is handled by the router redirect; failure is rendered below
    // from the provider state, so no BuildContext is touched after the await.
  }

  @override
  Widget build(BuildContext context) {
    final AuthState auth = ref.watch(authControllerProvider);
    final ApiFailure? failure = auth.failure;

    return AuthShell(
      heading: 'เข้าสู่ระบบ',
      purpose: 'ดูแลคนที่คุณรัก ให้ทุกคนในบ้านไปด้วยกัน',
      form: Form(
        key: _formKey,
        autovalidateMode: AutovalidateMode.onUserInteraction,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            if (failure != null)
              AuthErrorBanner(
                key: const Key('login-error'),
                message: failure.message,
              ),
            TextFormField(
              key: const Key('login-email'),
              controller: _email,
              keyboardType: TextInputType.emailAddress,
              textInputAction: TextInputAction.next,
              autofillHints: const <String>[AutofillHints.username],
              decoration: authInputDecoration(
                label: 'อีเมล',
                errorText: failure?.fieldErrors['email'],
              ),
              validator: _validateEmail,
            ),
            const SizedBox(height: AppSpacing.lg),
            TextFormField(
              key: const Key('login-password'),
              controller: _password,
              obscureText: true,
              enableSuggestions: false,
              autocorrect: false,
              textInputAction: TextInputAction.done,
              autofillHints: const <String>[AutofillHints.password],
              decoration: authInputDecoration(
                label: 'รหัสผ่าน',
                errorText: failure?.fieldErrors['password'],
              ),
              validator: _validatePassword,
              onFieldSubmitted: (_) => _submit(),
            ),
            const SizedBox(height: AppSpacing.lg),
            FilledButton(
              key: const Key('login-submit'),
              style: authPrimaryButtonStyle(),
              onPressed: auth.isLoading ? null : _submit,
              child: auth.isLoading
                  ? const SizedBox(
                      width: AppSpacing.xl,
                      height: AppSpacing.xl,
                      child: CircularProgressIndicator(
                        strokeWidth: 2,
                        color: AppColors.textOnAccent,
                      ),
                    )
                  : const Text('เข้าสู่ระบบ'),
            ),
            const SizedBox(height: AppSpacing.md),
            Center(
              child: TextButton(
                key: const Key('login-register-link'),
                onPressed: auth.isLoading
                    ? null
                    : () => context.go(RegisterPage.routePath),
                child: const Text('ยังไม่มีบัญชี? สมัครสมาชิก'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
