import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import {
  AbstractControl,
  NonNullableFormBuilder,
  ReactiveFormsModule,
  ValidationErrors,
  ValidatorFn,
  Validators,
} from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { toUserMessage } from '../../../core/api/problem-details';
import { AuthService } from '../../../core/auth/auth.service';

/** Mirrors the server rule: the password must be at least 10 and confirmed. */
const passwordsMatch: ValidatorFn = (control: AbstractControl): ValidationErrors | null => {
  const password = control.get('password')?.value as string | undefined;
  const confirmation = control.get('password_confirmation')?.value as string | undefined;

  if (password === undefined || confirmation === undefined) {
    return null;
  }

  return password === confirmation ? null : { passwordMismatch: true };
};

@Component({
  selector: 'app-register-page',
  imports: [ReactiveFormsModule, RouterLink],
  templateUrl: './register.page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class RegisterPage {
  private readonly formBuilder = inject(NonNullableFormBuilder);
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  protected readonly form = this.formBuilder.group(
    {
      name: ['', [Validators.required]],
      email: ['', [Validators.required, Validators.email]],
      password: ['', [Validators.required, Validators.minLength(10)]],
      password_confirmation: ['', [Validators.required]],
    },
    { validators: passwordsMatch },
  );

  protected readonly loading = signal(false);
  protected readonly errorMessage = signal<string | null>(null);
  protected readonly successMessage = signal<string | null>(null);

  protected get name() {
    return this.form.controls.name;
  }

  protected get email() {
    return this.form.controls.email;
  }

  protected get password() {
    return this.form.controls.password;
  }

  protected get confirmation() {
    return this.form.controls.password_confirmation;
  }

  protected async submit(): Promise<void> {
    if (this.loading()) {
      return;
    }

    this.errorMessage.set(null);
    this.successMessage.set(null);

    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    this.loading.set(true);

    try {
      const values = this.form.getRawValue();
      await firstValueFrom(
        this.auth.register({
          name: values.name,
          email: values.email,
          password: values.password,
          password_confirmation: values.password_confirmation,
        }),
      );

      this.successMessage.set('สมัครสมาชิกสำเร็จ กำลังพาไปยืนยันเบอร์โทรศัพท์');
      await this.router.navigate(['/verify-phone']);
    } catch (error) {
      this.errorMessage.set(toUserMessage(error));
    } finally {
      this.loading.set(false);
    }
  }
}
