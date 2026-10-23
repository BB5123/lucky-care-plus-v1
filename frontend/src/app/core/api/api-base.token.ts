import { InjectionToken } from '@angular/core';

/**
 * Base URL for the LuckyCare+ API.
 *
 * The dev server proxies `/api` to the Laravel process (see `proxy.conf.json`),
 * so same-origin requests are the default and CORS is never involved locally.
 */
export const API_BASE_URL = new InjectionToken<string>('API_BASE_URL', {
  providedIn: 'root',
  factory: () => '/api/v1',
});
