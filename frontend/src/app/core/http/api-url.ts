import { environment } from '../../../environments/environment';

/**
 * True when a request targets our own API.
 *
 * Interceptors use this so a bearer token, or a correlation id, is never
 * attached to a third-party host (a CDN, an OAuth provider) that has no
 * business seeing it.
 */
export function isApiRequest(url: string): boolean {
  return url.startsWith(environment.apiBaseUrl) || url.startsWith('/api/');
}
