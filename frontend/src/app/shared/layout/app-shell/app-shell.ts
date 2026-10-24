import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  computed,
  inject,
  signal,
} from '@angular/core';
import { NavigationEnd, Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { Subscription, filter, firstValueFrom } from 'rxjs';
import { AuthService } from '../../../core/auth/auth.service';
import { NotificationsService } from '../../../core/notifications/notifications.service';
import { ElderModeService } from '../../../core/preferences/elder-mode.service';
import { WorkspaceService } from '../../../core/workspace/workspace.service';

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
  private readonly workspace = inject(WorkspaceService);
  private readonly notifications = inject(NotificationsService);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly elderMode = inject(ElderModeService);

  protected readonly user = this.auth.user;
  protected readonly workspaces = this.workspace.activeWorkspaces;
  protected readonly currentWorkspace = this.workspace.currentWorkspace;
  protected readonly unreadCount = this.notifications.unreadCount;
  protected readonly loggingOut = signal(false);

  /** The five IA destinations from .ai/MOBILE_DESIGN.md §3. */
  protected readonly navItems: readonly NavItem[] = [
    { path: '/dashboard', label: 'หน้าแรก' },
    { path: '/health', label: 'คลังสุขภาพ' },
    { path: '/tasks', label: 'งานดูแล' },
    { path: '/timeline', label: 'ไทม์ไลน์' },
    { path: '/family', label: 'ครอบครัวและสิทธิ์' },
    { path: '/appointments', label: 'นัดหมาย' },
  ];

  protected readonly initials = computed(() => {
    const name = this.auth.user()?.name.trim();

    if (!name) {
      return '?';
    }

    return Array.from(name)[0]?.toUpperCase() ?? '?';
  });

  private readonly navigationSubscription: Subscription;

  constructor() {
    // Boot the workspace context after authentication. The dashboard shows the
    // invite empty-state; this call only makes the shell header aware of it.
    void this.workspace.load().catch(() => {
      // The dashboard surfaces a retryable error itself.
    });

    // Refresh the badge after each navigation, never on a timer (no polling).
    this.navigationSubscription = this.router.events
      .pipe(filter((event): event is NavigationEnd => event instanceof NavigationEnd))
      .subscribe(() => {
        void this.notifications.refreshUnreadCount();
      });

    this.destroyRef.onDestroy(() => {
      this.navigationSubscription.unsubscribe();
    });

    void this.notifications.refreshUnreadCount();
  }

  protected changeWorkspace(event: Event): void {
    const target = event.target as HTMLSelectElement | null;

    if (target !== null) {
      this.workspace.select(target.value);
    }
  }

  protected toggleElderMode(event: Event): void {
    const target = event.target as HTMLInputElement | null;

    if (target !== null) {
      this.elderMode.setEnabled(target.checked);
    }
  }

  protected async logout(): Promise<void> {
    if (this.loggingOut()) {
      return;
    }

    this.loggingOut.set(true);

    try {
      await firstValueFrom(this.auth.logout());
      this.workspace.reset();
      this.notifications.reset();
    } finally {
      this.loggingOut.set(false);
      await this.router.navigate(['/login']);
    }
  }
}
