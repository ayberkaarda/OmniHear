export const environment = {
  production: false,
  apiBaseUrl: 'http://localhost:8000/api',
  /**
   * Matches `backend/.env.example`: `REVERB_HOST=localhost`, `REVERB_PORT=8080`.
   * `key` is left empty on purpose — `REVERB_APP_KEY` is `REPLACE_ME` in the
   * example env, and an invented value would make the client chase a socket
   * that can never authorize it. Fill it in locally to exercise realtime; an
   * empty key keeps the app on its documented non-realtime behaviour.
   */
  reverb: {
    key: '',
    host: 'localhost',
    port: 8080,
    scheme: 'http'
  }
};
