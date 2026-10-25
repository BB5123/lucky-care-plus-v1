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

interface RetentionBanner {
  readonly title: string;
  readonly detail: string;
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
  protected readonly isPlatformAdmin = this.auth.isPlatformAdmin;
  protected readonly workspaces = this.workspace.activeWorkspaces;
  protected readonly currentWorkspace = this.workspace.currentWorkspace;
  protected readonly unreadCount = this.notifications.unreadCount;
  protected readonly loggingOut = signal(false);

  /**
   * Advisory banner for a non-active account. The API refuses the write with a
   * stable 409 either way; the banner exists so a member understands why before
   * they try, and can jump to the export card.
   */
  protected readonly retentionBanner = computed<RetentionBanner | null>(() => {
    switch (this.workspace.retentionState()) {
      case 'read_only':
        return {
          title: 'บัญชีนี้อยู่ในสถานะอ่านอย่างเดียว',
          detail:
            'ยังอ่านและส่งออกข้อมูลได้ แต่การแก้ไขถูกปิดไว้จนกว่าจะกลับมาใช้งานต่อ กรุณาติดต่อผู้ดูแลหากต้องการกู้คืน',
        };
      case 'archived':
        return {
          title: 'บัญชีนี้ถูกเก็บถาวร',
          detail:
            'ข้อมูลไม่ถูกโหลดเข้าสู่แอปแล้ว ท่านยังส่งออกข้อมูลได้ และสามารถติดต่อผู้ดูแลเพื่อกู้คืนบัญชี',
        };
      case 'scheduled_deletion':
        return {
          title: 'บัญชีนี้อยู่ในคิวการลบ',
          detail: 'กรุณาส่งออกข้อมูลที่ต้องการเก็บไว้ หรือติดต่อผู้ดูแลก่อนถึงกำหนดลบ',
        };
      default:
        return null;
    }
  });

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
    void this.workspace.load().then(
      () => this.workspace.loadLifecycle(),
      () => undefined,
    );

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

    if (target !== null && this.workspace.select(target.value)) {
      void this.workspace.loadLifecycle();
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
