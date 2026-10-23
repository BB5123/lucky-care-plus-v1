import {
  HttpClient,
  HttpErrorResponse,
  provideHttpClient,
  withInterceptors,
} from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { AuthService } from './auth.service';
import { authInterceptor } from './auth.interceptor';
import { AuthResponse } from './auth.models';

const AUTH_RESPONSE: AuthResponse = {
  user: {
    id: 'user-1',
    name: 'Test User',
    email: 'user@example.test',
    phone: null,
    phone_verified_at: null,
  },
  token: 'token-123',
  token_type: 'Bearer',
};

function problem401(): { status: number; code: string; detail: string; message: string } {
  return {
    status: 401,
    code: 'unauthenticated',
    detail: 'กรุณายืนยันตัวตนก่อนเข้าถึงข้อมูล',
    message: 'กรุณายืนยันตัวตนก่อนเข้าถึงข้อมูล',
  };
}

describe('authInterceptor', () => {
  let http: HttpClient;
  let httpMock: HttpTestingController;
  let auth: AuthService;

  beforeEach(() => {
    sessionStorage.clear();

    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(withInterceptors([authInterceptor])),
        provideHttpClientTesting(),
        provideRouter([]),
      ],
    });

    http = TestBed.inject(HttpClient);
    httpMock = TestBed.inject(HttpTestingController);
    auth = TestBed.inject(AuthService);
  });

  afterEach(() => {
    httpMock.verify();
    sessionStorage.clear();
    TestBed.resetTestingModule();
  });

  async function login(): Promise<void> {
    const promise = firstValueFrom(
      auth.login({ email: 'user@example.test', password: 'correct-horse' }),
    );

    const request = httpMock.expectOne('/api/v1/auth/login');
    expect(request.request.headers.has('Authorization')).toBe(false);
    request.flush({ data: AUTH_RESPONSE });

    await promise;
  }

  it('FE-T03 adds the bearer header only when a token exists', async () => {
    http.get('/probe/anonymous').subscribe();
    const anonymous = httpMock.expectOne('/probe/anonymous');
    expect(anonymous.request.headers.has('Authorization')).toBe(false);
    expect(anonymous.request.headers.get('Accept')).toBe('application/json');
    anonymous.flush({});

    await login();

    http.get('/probe/authenticated').subscribe();
    const authenticated = httpMock.expectOne('/probe/authenticated');
    expect(authenticated.request.headers.get('Authorization')).toBe('Bearer token-123');
    expect(authenticated.request.headers.get('Accept')).toBe('application/json');
    authenticated.flush({});
  });

  it('FE-T04 clears the session on 401 and redirects to login once', async () => {
    await login();

    const router = TestBed.inject(Router);
    const navigate = vi.spyOn(router, 'navigate').mockResolvedValue(true);

    let receivedError: unknown = null;

    http.get('/probe/secure').subscribe({
      error: (error: unknown) => {
        receivedError = error;
      },
    });

    const request = httpMock.expectOne('/probe/secure');
    request.flush(problem401(), { status: 401, statusText: 'Unauthorized' });

    expect(receivedError).toBeInstanceOf(HttpErrorResponse);
    expect(auth.token()).toBeNull();
    expect(auth.isAuthenticated()).toBe(false);
    expect(sessionStorage.getItem('luckycare.token')).toBeNull();
    expect(navigate).toHaveBeenCalledTimes(1);
    expect(navigate).toHaveBeenCalledWith(['/login'], { queryParams: {} });
  });
});
