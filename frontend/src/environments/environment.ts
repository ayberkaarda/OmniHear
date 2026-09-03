export const environment = {
  production: true,
  apiBaseUrl: 'http://localhost:8000/api',
  /**
   * Reverb, for the private `company.{id}` channel of spec 6.5.
   *
   * `key` mirrors `REVERB_APP_KEY`, which is the *public* half of the pair and
   * is meant to reach the browser. `REVERB_APP_SECRET` is server-side only and
   * must never appear in this file or anywhere else the bundle can read
   * (`docs/contracts/realtime.md` section 4).
   *
   * An empty `key` disables realtime entirely: `RealtimeService` then never
   * runs its dynamic `import()`, so a deployment without a websocket tier does
   * not even download pusher-js. The application behaves exactly as it did
   * before this seam existed — data is still correct after a manual refresh.
   */
  reverb: {
    key: '',
    host: 'localhost',
    port: 8080,
    scheme: 'http'
  }
};
