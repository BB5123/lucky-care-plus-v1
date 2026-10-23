import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  computed,
  inject,
  signal,
} from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { toUserMessage } from '../../../core/api/problem-details';
import { AuthService } from '../../../core/auth/auth.service';

const PHONE_PATTERN = /^\+?[0-9]{9,15}$/;

function formatSeconds(totalSeconds: number): string {
  const safeSeconds = Math.max(0, Math.floor(totalSeconds));
  const minutes = Math.floor(safeSeconds / 60);
  const seconds = safeSeconds % 60;

  return `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
}

@Component({
  selector: 'app-verify-phone-page',
  imports: [ReactiveFormsModule, RouterLink],
  templateUrl: './verify-phone.page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class VerifyPhonePage {
  private readonly formBuilder = inject(NonNullableFormBuilder);
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly form = this.formBuilder.group({
    phone: ['', [Validators.required, Validators.pattern(PHONE_PATTERN)]],
    code: ['', [Validators.required, Validators.pattern(/^[0-9]{6}$/)]],
  });

  protected readonly requesting = signal(false);
  protected readonly verifying = signal(false);
  protected readonly errorMessage = signal<string | null>(null);
  protected readonly requestMessage = signal<string | null>(null);
  protected readonly successMessage = signal<string | null>(null);
  protected readonly demoCode = signal<string | null>(null);
  protected readonly expiresIn = signal(0);
  protected readonly resendIn = signal(0);

  protected readonly expiresLabel = computed(() => formatSeconds(this.expiresIn()));
  protected readonly resendLabel = computed(() => formatSeconds(this.resendIn()));

  private countdownTimer: ReturnType<typeof setInterval> | null = null;
  private navigationTimer: ReturnType<typeof setTimeout> | null = null;

  constructor() {
    this.destroyRef.onDestroy(() => this.clearTimers());
  }

  protected get phone() {
    return this.form.controls.phone;
  }

  protected get code() {
    return this.form.controls.code;
  }

  protected async requestCode(): Promise<void> {
    if (this.requesting() || this.resendIn() > 0) {
      return;
    }

    this.errorMessage.set(null);
    this.requestMessage.set(null);
    this.successMessage.set(null);
    this.demoCode.set(null);

    if (this.phone.invalid) {
      this.phone.markAsTouched();
      this.errorMessage.set('กรุณากรอกเบอร์โทรศัพท์ให้ถูกต้อง');
      return;
    }

    this.requesting.set(true);

    try {
      const result = await firstValueFrom(this.auth.requestPhoneOtp(this.phone.value));

      this.requestMessage.set(result.message);
      this.startCountdown(result.expires_in);

      // Development-only panel. It is rendered only when the API explicitly
      // returns `demo_mode: true` together with a code; production never does.
      if (result.demo_mode === true && typeof result.demo_code === 'string') {
        this.demoCode.set(result.demo_code);
      }
    } catch (error) {
      this.errorMessage.set(toUserMessage(error));
    } finally {
      this.requesting.set(false);
    }
  }

  protected useDemoCode(code: string): void {
    this.code.setValue(code);
    this.code.markAsTouched();
  }

  protected async verify(): Promise<void> {
    if (this.verifying()) {
      return;
    }

    this.errorMessage.set(null);
    this.successMessage.set(null);

    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    this.verifying.set(true);

    try {
      const values = this.form.getRawValue();
      const result = await firstValueFrom(this.auth.verifyPhone(values.phone, values.code));

      this.auth.markPhoneVerified(result.phone, result.phone_verified_at);
      this.successMessage.set('ยืนยันเบอร์โทรสำเร็จ');
      this.stopCountdown();

      this.navigationTimer = setTimeout(() => {
        void this.router.navigate(['/dashboard']);
      }, 1200);
    } catch (error) {
      this.errorMessage.set(toUserMessage(error));
    } finally {
      this.verifying.set(false);
    }
  }

  private startCountdown(seconds: number): void {
    this.stopCountdown();

    const total = Math.max(0, Math.floor(seconds));
    this.expiresIn.set(total);
    this.resendIn.set(Math.min(60, total));

    this.countdownTimer = setInterval(() => {
      this.expiresIn.update((value) => Math.max(0, value - 1));
      this.resendIn.update((value) => Math.max(0, value - 1));

      if (this.expiresIn() === 0) {
        this.stopCountdown();
      }
    }, 1000);
  }

  private stopCountdown(): void {
    if (this.countdownTimer !== null) {
      clearInterval(this.countdownTimer);
      this.countdownTimer = null;
    }
  }

  private clearTimers(): void {
    this.stopCountdown();

    if (this.navigationTimer !== null) {
      clearTimeout(this.navigationTimer);
      this.navigationTimer = null;
    }
  }
}
