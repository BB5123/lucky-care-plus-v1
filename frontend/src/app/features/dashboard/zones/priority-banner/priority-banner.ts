import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';
import { AlertItem, AlertsZone } from '../../../../core/dashboard/dashboard.models';
import { translateAlertMessage } from '../alert-messages';

/**
 * Z2 — Priority alerts. Only rendered when the server returned at least one
 * item; `message_key` is translated locally and never contains a health value.
 */
@Component({
  selector: 'app-priority-banner',
  templateUrl: './priority-banner.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PriorityBanner {
  readonly zone = input<AlertsZone | null>(null);
  readonly loading = input(false);
  readonly error = input<string | null>(null);
  readonly personNames = input<Readonly<Record<string, string>>>({});

  readonly retry = output<void>();

  protected readonly skeletons = [1, 2];

  protected alertText(alert: AlertItem): string {
    const message = translateAlertMessage(alert.message_key);

    if (alert.person_id === null) {
      return message;
    }

    const name = this.personNames()[alert.person_id] ?? '';

    return name === '' ? message : `${name}: ${message}`;
  }
}
