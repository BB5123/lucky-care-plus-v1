import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/auth/auth_controller.dart';
import '../../core/auth/auth_state.dart';
import '../../core/auth/auth_validation.dart';
import '../../core/network/api_failure.dart';
import '../../design_system/tokens.dart';
import 'login_page.dart';
import 'widgets/auth_shell.dart';

/// `/register` — name, email, password, confirmation.
///
/// Validation mirrors `AuthController::register`: password ≥ 10 characters and
/// `password_confirmation` must match.
class RegisterPage extends ConsumerStatefulWidget {
  const RegisterPage({super.key});

  static const String routePath = '/register';

  @override
  ConsumerState<RegisterPage> createState() => _RegisterPageState();
}

class _RegisterPageState extends ConsumerState<RegisterPage> {
  final GlobalKey<FormState> _formKey = GlobalKey<FormState>();
  final TextEditingController _name = TextEditingController();
  final TextEditingController _email = TextEditingController();
  final TextEditingController _password = TextEditingController();
  final TextEditingController _confirmation = TextEditingController();

  @override
  void dispose() {
    _name.dispose();
    _email.dispose();
    _password.dispose();
    _confirmation.dispose();
    super.dispose();
  }

  String? _validateName(String? value) {
    if ((value ?? '').trim().isEmpty) {
      return 'กรุณากรอกชื่อ';
    }

    return null;
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

  String? _validateConfirmation(String? value) {
    final String confirmation = value ?? '';

    if (confirmation.isEmpty) {
      return 'กรุณายืนยันรหัสผ่าน';
    }

    if (!AuthValidator.passwordsMatch(_password.text, confirmation)) {
      return 'รหัสผ่านทั้งสองช่องไม่ตรงกัน';
    }

    return null;
  }

  Future<void> _submit() async {
    final AuthState auth = ref.read(authControllerProvider);

    if (auth.isLoading) {
      return;
    }

    final FormState? form = _formKey.currentState;

    if (form == null || !form.validate()) {
      return;
    }

    await ref
        .read(authControllerProvider.notifier)
        .signUp(
          name: _name.text.trim(),
          email: _email.text.trim(),
          password: _password.text,
          confirmation: _confirmation.text,
        );
    // Success is handled by the router redirect; failure is rendered below.
  }

  @override
  Widget build(BuildContext context) {
    final AuthState auth = ref.watch(authControllerProvider);
    final ApiFailure? failure = auth.failure;

    return AuthShell(
      heading: 'สร้างบัญชีใหม่',
      purpose: 'เริ่มต้นดูแลคนที่คุณรักได้ในไม่กี่นาที',
      form: Form(
        key: _formKey,
        autovalidateMode: AutovalidateMode.onUserInteraction,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            if (failure != null)
              AuthErrorBanner(
                key: const Key('register-error'),
                message: failure.message,
              ),
            TextFormField(
              key: const Key('register-name'),
              controller: _name,
              textInputAction: TextInputAction.next,
              autofillHints: const <String>[AutofillHints.name],
              decoration: authInputDecoration(
                label: 'ชื่อที่ใช้แสดง',
                errorText: failure?.fieldErrors['name'],
              ),
              validator: _validateName,
            ),
            const SizedBox(height: AppSpacing.lg),
            TextFormField(
              key: const Key('register-email'),
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
              key: const Key('register-password'),
              controller: _password,
              obscureText: true,
              enableSuggestions: false,
              autocorrect: false,
              textInputAction: TextInputAction.next,
              autofillHints: const <String>[AutofillHints.newPassword],
              decoration: authInputDecoration(
                label: 'รหัสผ่าน',
                hintText: 'อย่างน้อย 10 ตัวอักษร',
                errorText: failure?.fieldErrors['password'],
              ),
              validator: _validatePassword,
            ),
            const SizedBox(height: AppSpacing.lg),
            TextFormField(
              key: const Key('register-confirm'),
              controller: _confirmation,
              obscureText: true,
              enableSuggestions: false,
              autocorrect: false,
              textInputAction: TextInputAction.done,
              decoration: authInputDecoration(
                label: 'ยืนยันรหัสผ่าน',
                errorText: failure?.fieldErrors['password_confirmation'],
              ),
              validator: _validateConfirmation,
              onFieldSubmitted: (_) => _submit(),
            ),
            const SizedBox(height: AppSpacing.lg),
            FilledButton(
              key: const Key('register-submit'),
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
                  : const Text('สมัครสมาชิก'),
            ),
            const SizedBox(height: AppSpacing.md),
            Center(
              child: TextButton(
                key: const Key('register-login-link'),
                onPressed: auth.isLoading
                    ? null
                    : () => context.go(LoginPage.routePath),
                child: const Text('มีบัญชีอยู่แล้ว? เข้าสู่ระบบ'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
