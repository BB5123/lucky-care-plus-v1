import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import {
  ActivatedRouteSnapshot,
  RouterStateSnapshot,
  UrlTree,
  provideRouter,
} from '@angular/router';
import { AuthService } from './auth.service';
import { guestGuard } from './guest.guard';

function routeSnapshot(): ActivatedRouteSnapshot {
  return {} as ActivatedRouteSnapshot;
}

function routerState(url: string): RouterStateSnapshot {
  return { url } as RouterStateSnapshot;
}

describe('guestGuard', () => {
  beforeEach(() => {
    sessionStorage.clear();

    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([])],
    });
  });

  afterEach(() => {
    sessionStorage.clear();
    TestBed.resetTestingModule();
  });

  it('FE-T06 redirects an authenticated user away from /login', () => {
    sessionStorage.setItem('luckycare.token', 'token-123');

    const auth = TestBed.inject(AuthService);
    expect(auth.isAuthenticated()).toBe(true);

    const result = TestBed.runInInjectionContext(() =>
      guestGuard(routeSnapshot(), routerState('/login')),
    );

    expect(result).toBeInstanceOf(UrlTree);
    expect((result as UrlTree).toString()).toBe('/dashboard');
  });

  it('allows a visitor without a token to reach /login', () => {
    const result = TestBed.runInInjectionContext(() =>
      guestGuard(routeSnapshot(), routerState('/login')),
    );

    expect(result).toBe(true);
  });
});
