import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';

import { environment } from '../../../environments/environment';
import { makePlatformList } from '../settings/settings.fixtures';
import { PlatformsStore } from './platforms.store';

const PLATFORMS = `${environment.apiBaseUrl}/v1/integrations/platforms`;

describe('PlatformsStore', () => {
  let store: PlatformsStore;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()]
    });
    store = TestBed.inject(PlatformsStore);
    http = TestBed.inject(HttpTestingController);
    store.reset();
  });

  afterEach(() => http.verify());

  it('publishes the registry the server actually holds', () => {
    store.load();
    http.expectOne(PLATFORMS).flush(makePlatformList());

    expect(store.platformNames()).toEqual(['appstore', 'zendesk']);
    expect(store.settingsFor('appstore').map((field) => field.key)).toEqual(['app_id', 'country']);
    expect(store.credentialsFor('appstore')).toEqual([]);
    expect(store.credentialsFor('zendesk').map((field) => field.key)).toEqual(['email', 'api_token']);
  });

  /**
   * The drift this store exists to end: a platform added on the backend shows
   * up here without a frontend change. The old constant had to be edited by
   * hand, and was not, the first time it mattered.
   */
  it('picks up a platform nobody added to the frontend', () => {
    store.load();
    http.expectOne(PLATFORMS).flush(
      makePlatformList([
        { platform: 'googleplay', requires_credentials: true, settings: [{ key: 'package_name', required: true }], credentials: [{ key: 'service_account_json', required: true }] }
      ])
    );

    expect(store.platformNames()).toEqual(['googleplay']);
    expect(store.settingsFor('googleplay').map((field) => field.key)).toEqual(['package_name']);
  });

  it('answers with nothing for a platform it has never heard of', () => {
    store.load();
    http.expectOne(PLATFORMS).flush(makePlatformList());

    expect(store.descriptorFor('trustpilot')).toBeNull();
    expect(store.settingsFor('trustpilot')).toEqual([]);
    expect(store.settingsFor(null)).toEqual([]);
  });

  it('reads once, and reports a failure rather than guessing a list', () => {
    store.loadIfNeeded();
    http
      .expectOne(PLATFORMS)
      .flush({ code: 'SERVER_ERROR', message: 'boom' }, { status: 500, statusText: 'Server Error' });

    expect(store.state()).toBe('error');
    expect(store.errorCode()).toBe('SERVER_ERROR');
    expect(store.platformNames()).toEqual([]);

    store.loadIfNeeded();
    http.expectNone(PLATFORMS);
  });
});
