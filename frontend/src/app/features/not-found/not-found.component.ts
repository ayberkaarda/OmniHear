import { ChangeDetectionStrategy, Component } from '@angular/core';
import { RouterLink } from '@angular/router';

import { ButtonStyleDirective } from '../../shared/ui/button/button-style.directive';

@Component({
    selector: 'app-not-found',
    imports: [RouterLink, ButtonStyleDirective],
    templateUrl: './not-found.component.html',
    changeDetection: ChangeDetectionStrategy.OnPush
})
export class NotFoundComponent {}
