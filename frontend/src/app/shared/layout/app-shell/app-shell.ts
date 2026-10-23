import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { AuthService } from '../../../core/auth/auth.service';

interface NavItem {
  readonly path: string;
  readonly label: string;
}

@Component({
  selector: 'app-shell',
  imports: [RouterLink, RouterLinkActive, RouterOutlet],
  templateUrl: './app-shell.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AppShell {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  protected readonly user = this.auth.user;
  protected readonly loggingOut = signal(false);

  /** The five IA destinations from .ai/MOBILE_DESIGN.md §3. */
  protected readonly navItems: readonly NavItem[] = [
    { path: '/dashboard', label: 'หน้าแรก' },
    { path: '/health', label: 'คลังสุขภาพ' },
    { path: '/tasks', label: 'งานดูแล' },
    { path: '/appointments', label: 'นัดหมาย' },
    { path: '/family', label: 'ครอบครัวและสิทธิ์' },
  ];

  protected readonly initials = computed(() => {
    const name = this.auth.user()?.name.trim();

    if (!name) {
      return '?';
    }

    return Array.from(name)[0]?.toUpperCase() ?? '?';
  });

  protected async logout(): Promise<void> {
    if (this.loggingOut()) {
      return;
    }

    this.loggingOut.set(true);

    try {
      await firstValueFrom(this.auth.logout());
    } finally {
      this.loggingOut.set(false);
      await this.router.navigate(['/login']);
    }
  }
}
