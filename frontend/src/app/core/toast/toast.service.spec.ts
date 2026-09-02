import { TestBed } from '@angular/core/testing';

import { ToastService } from './toast.service';

describe('ToastService', () => {
  let service: ToastService;

  beforeEach(() => {
    jest.useFakeTimers();
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({ providers: [ToastService] });
    service = TestBed.inject(ToastService);
  });

  afterEach(() => {
    jest.useRealTimers();
  });

  it('queues toasts in order with distinct ids', () => {
    const first = service.show('one');
    const second = service.error('two');

    expect(service.toasts().map((t) => t.message)).toEqual(['one', 'two']);
    expect(first).not.toBe(second);
    expect(service.toasts()[1].tone).toBe('error');
  });

  it('auto-dismisses after the default duration', () => {
    service.show('temporary');
    expect(service.toasts()).toHaveLength(1);

    jest.advanceTimersByTime(6000);

    expect(service.toasts()).toHaveLength(0);
  });

  it('keeps a toast forever when the duration is 0', () => {
    service.show('sticky', 'info', 0);

    jest.advanceTimersByTime(60_000);

    expect(service.toasts()).toHaveLength(1);
  });

  it('dismiss removes only the requested toast and cancels its timer', () => {
    const keep = service.show('keep', 'info', 0);
    const drop = service.show('drop', 'info', 0);

    service.dismiss(drop);

    expect(service.toasts().map((t) => t.id)).toEqual([keep]);
  });

  it('clear empties the queue', () => {
    service.success('a');
    service.error('b');

    service.clear();

    expect(service.toasts()).toHaveLength(0);
  });
});
