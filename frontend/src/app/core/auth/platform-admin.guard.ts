import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { AuthService } from './auth.service';

/**
 * Keeps the platform knowledge screen out of a non-admin session.
 *
 * A direct reload can reach the router before `App` has finished rehydrating
 * `/me`, so a missing user is refreshed once instead of being mistaken for a
 * denial. The server remains authoritative: if the capability was revoked the
 * page still handles a stale-token 403 itself.
 */
export const platformAdminGuard: CanActivateFn = () => {
  const auth = inject(AuthService);
  const router = inject(Router);

  const decide = (): boolean | ReturnType<Router['createUrlTree']> =>
    auth.isPlatformAdmin() ? true : router.createUrlTree(['/dashboard']);

  if (auth.user() !== null) {
    return decide();
  }

  return firstValueFrom(auth.refreshMe()).then(
    () => decide(),
    () => router.createUrlTree(['/login']),
  );
};
