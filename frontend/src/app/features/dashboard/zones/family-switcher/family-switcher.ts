import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';
import { FamilyZone } from '../../../../core/dashboard/dashboard.models';

/**
 * Z1 — Family switcher and status pills. No HTTP here: selecting a person only
 * emits the id, and the dashboard page performs the re-query.
 */
@Component({
  selector: 'app-family-switcher',
  templateUrl: './family-switcher.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class FamilySwitcher {
  readonly zone = input<FamilyZone | null>(null);
  readonly loading = input(false);
  readonly error = input<string | null>(null);
  readonly selectedProfileId = input<string | null>(null);

  readonly retry = output<void>();
  readonly profileSelected = output<string | null>();

  protected readonly skeletons = [1, 2, 3];

  protected select(personId: string | null): void {
    if (this.selectedProfileId() === personId) {
      return;
    }

    this.profileSelected.emit(personId);
  }

  protected statusLabel(status: string): string {
    switch (status) {
      case 'green':
        return 'สถานะปกติ';
      case 'yellow':
        return 'มีเรื่องที่ควรติดตาม';
      case 'red':
        return 'ต้องดูแลทันที';
      default:
        return 'ไม่ทราบสถานะ';
    }
  }
}
