import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { AuthService } from './core/auth/auth.service';

@Component({
  selector: 'app-root',
  imports: [RouterOutlet],
  template: '<router-outlet />',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class App {
  private readonly auth = inject(AuthService);

  constructor() {
    // Rehydrate the signed-in user when a token survived in sessionStorage.
    void this.restoreSession();
  }

  private async restoreSession(): Promise<void> {
    try {
      await firstValueFrom(this.auth.restore());
    } catch {
      // `restore()` already fails closed and clears the local session.
    }
  }
}
