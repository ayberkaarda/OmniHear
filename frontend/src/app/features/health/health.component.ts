import { ChangeDetectionStrategy, Component, inject, OnInit, signal } from '@angular/core';

import { HealthService } from '../../core/health/health.service';

type HealthState = 'loading' | 'success' | 'error';

@Component({
  selector: 'app-health',
  standalone: true,
  imports: [],
  templateUrl: './health.component.html',
  styleUrl: './health.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class HealthComponent implements OnInit {
  private readonly healthService = inject(HealthService);

  readonly state = signal<HealthState>('loading');
  readonly status = signal<string | null>(null);

  ngOnInit(): void {
    this.healthService.getHealth().subscribe({
      next: (response) => {
        this.status.set(response.status);
        this.state.set('success');
      },
      error: () => {
        this.state.set('error');
      }
    });
  }
}
