import { Injectable, signal } from '@angular/core';

const ELDER_MODE_STORAGE_KEY = 'luckycare.elder-mode';

function readStoredFlag(): boolean {
  try {
    return window.localStorage.getItem(ELDER_MODE_STORAGE_KEY) === '1';
  } catch {
    return false;
  }
}

function writeStoredFlag(enabled: boolean): void {
  try {
    if (enabled) {
      window.localStorage.setItem(ELDER_MODE_STORAGE_KEY, '1');
    } else {
      window.localStorage.removeItem(ELDER_MODE_STORAGE_KEY);
    }
  } catch {
    // The in-memory preference still applies for this tab.
  }
}

/**
 * Elder mode (blueprint MOBILE_DESIGN §6): large type and a single-focus
 * dashboard. The preference is a pure presentation choice and is persisted
 * per device, never sent to the server.
 */
@Injectable({ providedIn: 'root' })
export class ElderModeService {
  private readonly enabledState = signal(readStoredFlag());

  readonly enabled = this.enabledState.asReadonly();

  setEnabled(enabled: boolean): void {
    this.enabledState.set(enabled);
    writeStoredFlag(enabled);
  }

  toggle(): void {
    this.setEnabled(!this.enabledState());
  }
}
