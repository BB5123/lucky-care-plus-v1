import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, throwError } from 'rxjs';
import { AuthService } from './auth.service';

/**
 * Guards a burst of 401 responses (e.g. a dashboard firing several calls at
 * once) so the user is redirected exactly once and cannot enter a loop.
 */
let redirectInFlight = false;

function redirectToLogin(router: Router): void {
  if (redirectInFlight) {
    return;
  }

  const currentUrl = router.url;

  if (currentUrl.startsWith('/login')) {
    return;
  }

  redirectInFlight = true;

  void router
    .navigate(['/login'], {
      queryParams: currentUrl && currentUrl !== '/' ? { returnUrl: currentUrl } : {},
    })
    .finally(() => {
      redirectInFlight = false;
    });
}

/**
 * Adds the bearer token and JSON `Accept` header, and expires the local
 * session on a 401. Authorization decisions themselves stay on the server.
 */
export const authInterceptor: HttpInterceptorFn = (request, next) => {
  const auth = inject(AuthService);
  const router = inject(Router);
  const token = auth.token();

  const headers: Record<string, string> = { Accept: 'application/json' };

  if (token !== null) {
    headers['Authorization'] = `Bearer ${token}`;
  }

  return next(request.clone({ setHeaders: headers })).pipe(
    catchError((error: unknown) => {
      if (error instanceof HttpErrorResponse && error.status === 401) {
        auth.clearSession();
        redirectToLogin(router);
      }

      return throwError(() => error);
    }),
  );
};
