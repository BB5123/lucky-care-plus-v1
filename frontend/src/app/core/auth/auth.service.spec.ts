import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { firstValueFrom } from 'rxjs';
import { AuthService } from './auth.service';
import { AuthResponse } from './auth.models';

const AUTH_RESPONSE: AuthResponse = {
  user: {
    id: 'user-1',
    name: 'Test User',
    email: 'user@example.test',
    phone: null,
    phone_verified_at: null,
    is_platform_admin: false,
  },
  token: 'token-abc',
  token_type: 'Bearer',
};

describe('AuthService', () => {
  let service: AuthService;
  let httpMock: HttpTestingController;

  beforeEach(() => {
    sessionStorage.clear();

    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });

    service = TestBed.inject(AuthService);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpMock.verify();
    sessionStorage.clear();
    TestBed.resetTestingModule();
  });

  it('FE-T01 stores the token and marks the session authenticated after login', async () => {
    const resultPromise = firstValueFrom(
      service.login({ email: 'user@example.test', password: 'correct-horse' }),
    );

    const request = httpMock.expectOne('/api/v1/auth/login');
    expect(request.request.method).toBe('POST');
    expect(request.request.body.email).toBe('user@example.test');
    expect(request.request.body.password).toBe('correct-horse');
    expect(request.request.body.device_name).toMatch(/^web-/);

    request.flush({ data: AUTH_RESPONSE });

    const user = await resultPromise;

    expect(user.name).toBe('Test User');
    expect(service.token()).toBe('token-abc');
    expect(service.isAuthenticated()).toBe(true);
    expect(sessionStorage.getItem('luckycare.token')).toBe('token-abc');
  });

  it('FE-T02 clears memory and sessionStorage on logout', async () => {
    const loginPromise = firstValueFrom(
      service.login({ email: 'user@example.test', password: 'correct-horse' }),
    );
    httpMock.expectOne('/api/v1/auth/login').flush({ data: AUTH_RESPONSE });
    await loginPromise;

    expect(service.isAuthenticated()).toBe(true);

    const logoutPromise = firstValueFrom(service.logout());
    httpMock.expectOne('/api/v1/auth/logout').flush(null, {
      status: 204,
      statusText: 'No Content',
    });
    await logoutPromise;

    expect(service.token()).toBeNull();
    expect(service.isAuthenticated()).toBe(false);
    expect(service.user()).toBeNull();
    expect(sessionStorage.getItem('luckycare.token')).toBeNull();
  });
});
