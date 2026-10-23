import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { RouterLink } from '@angular/router';

@Component({
  selector: 'app-under-construction-page',
  imports: [RouterLink],
  templateUrl: './under-construction.page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class UnderConstructionPage {
  readonly title = input<string>('หน้านี้');
}
