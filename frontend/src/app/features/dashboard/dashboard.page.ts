import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { toUserMessage } from '../../core/api/problem-details';
import { AuthService } from '../../core/auth/auth.service';

const ROLE_LABELS: Readonly<Record<string, string>> = {
  profile_owner: 'เจ้าของโปรไฟล์',
  workspace_admin: 'ผู้ดูแลครอบครัว',
  caregiver: 'ผู้ดูแล',
  viewer: 'ผู้เข้าชม',
};

@Component({
  selector: 'app-dashboard-page',
  imports: [RouterLink],
  templateUrl: './dashboard.page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class DashboardPage {
  private readonly auth = inject(AuthService);

  protected readonly user = this.auth.user;
  protected readonly memberships = this.auth.memberships;
  protected readonly loading = signal(true);
  protected readonly errorMessage = signal<string | null>(null);
  protected readonly needsPhoneVerification = computed(() => {
    const user = this.auth.user();

    return user !== null && user.phone_verified_at === null;
  });

  constructor() {
    void this.load();
  }

  protected roleLabel(role: string): string {
    return ROLE_LABELS[role] ?? 'สมาชิก';
  }

  protected async load(): Promise<void> {
    this.loading.set(true);
    this.errorMessage.set(null);

    try {
      await firstValueFrom(this.auth.refreshMe());
    } catch (error) {
      this.errorMessage.set(toUserMessage(error));
    } finally {
      this.loading.set(false);
    }
  }
}
