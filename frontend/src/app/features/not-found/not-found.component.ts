import { ChangeDetectionStrategy, Component } from '@angular/core';
import { RouterLink } from '@angular/router';

import { ButtonStyleDirective } from '../../shared/ui/button/button-style.directive';
import { LogoComponent } from '../../shared/ui/logo/logo.component';

@Component({
    selector: 'app-not-found',
    imports: [RouterLink, ButtonStyleDirective, LogoComponent],
    templateUrl: './not-found.component.html',
    styleUrl: './not-found.component.scss',
    changeDetection: ChangeDetectionStrategy.OnPush
})
export class NotFoundComponent {}
