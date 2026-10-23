import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { AuthService } from '../../../core/auth/auth.service';
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

  protected readonly elderMode = inject(ElderModeService);

  protected readonly user = this.auth.user;
  protected readonly workspaces = this.workspace.activeWorkspaces;
  protected readonly currentWorkspace = this.workspace.currentWorkspace;
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

  constructor() {
    // Boot the workspace context after authentication. The dashboard shows the
    // invite empty-state; this call only makes the shell header aware of it.
    void this.workspace.load().catch(() => {
      // The dashboard surfaces a retryable error itself.
    });
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
    } finally {
      this.loggingOut.set(false);
      await this.router.navigate(['/login']);
    }
  }
}
