export const environment = {
  production: false,
  apiBaseUrl: 'http://localhost:8000/api',
  /**
   * Matches `infra/docker-compose.dev.yml`: `REVERB_HOST=localhost`,
   * `REVERB_PORT=8080`. `REVERB_APP_KEY` is a public client identifier, not a
   * secret, so it is safe to bake into this bundle — the compose `backend`,
   * `horizon` and `reverb` services all default to this same value
   * (`omnihear-dev-reverb-key`) when the environment variable is unset, which
   * is how the dev stack runs realtime without any per-developer setup.
   * `backend/.env.example` still ships `REPLACE_ME`, but the running
   * containers no longer read that placeholder: see `docs/contracts/realtime.md`.
   */
  reverb: {
    key: 'omnihear-dev-reverb-key',
    host: 'localhost',
    port: 8080,
    scheme: 'http'
  }
};
