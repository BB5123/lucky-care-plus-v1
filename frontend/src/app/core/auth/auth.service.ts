import { HttpClient } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { Observable, catchError, finalize, map, of, tap } from 'rxjs';
import { API_BASE_URL } from '../api/api-base.token';
import { getDeviceName } from '../device/device-name';
import {
  ApiEnvelope,
  AuthResponse,
  AuthUser,
  LoginRequest,
  MeResponse,
  MembershipSummary,
  OtpRequestResult,
  PhoneVerificationResult,
  RegisterRequest,
} from './auth.models';

const TOKEN_STORAGE_KEY = 'luckycare.token';

function readStoredToken(): string | null {
  try {
    return sessionStorage.getItem(TOKEN_STORAGE_KEY);
  } catch {
    return null;
  }
}

function writeStoredToken(token: string): void {
  try {
    sessionStorage.setItem(TOKEN_STORAGE_KEY, token);
  } catch {
    // Memory state is still authoritative for this tab.
  }
}

function removeStoredToken(): void {
  try {
    sessionStorage.removeItem(TOKEN_STORAGE_KEY);
  } catch {
    // Nothing to clean up when storage is unavailable.
  }
}

/**
 * Global identity state. The server remains the only authority on what the
 * user may see; this service stores the token and the user's own profile.
 */
@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = inject(API_BASE_URL);

  private readonly tokenState = signal<string | null>(readStoredToken());
  private readonly userState = signal<AuthUser | null>(null);
  private readonly membershipsState = signal<readonly MembershipSummary[]>([]);

  readonly token = this.tokenState.asReadonly();
  readonly user = this.userState.asReadonly();
  readonly memberships = this.membershipsState.asReadonly();
  readonly isAuthenticated = computed(() => this.tokenState() !== null);

  login(credentials: LoginRequest): Observable<AuthUser> {
    const body: LoginRequest = {
      ...credentials,
      device_name: credentials.device_name ?? getDeviceName(),
    };

    return this.http.post<ApiEnvelope<AuthResponse>>(`${this.baseUrl}/auth/login`, body).pipe(
      tap((response) => this.applySession(response.data)),
      map((response) => response.data.user),
    );
  }

  register(payload: RegisterRequest): Observable<AuthUser> {
    const body: RegisterRequest = {
      ...payload,
      device_name: payload.device_name ?? getDeviceName(),
    };

    return this.http.post<ApiEnvelope<AuthResponse>>(`${this.baseUrl}/auth/register`, body).pipe(
      tap((response) => this.applySession(response.data)),
      map((response) => response.data.user),
    );
  }

  /**
   * Revokes the server token (best effort) and always clears local state.
   */
  logout(): Observable<void> {
    if (this.tokenState() === null) {
      this.clearSession();
      return of(void 0);
    }

    return this.http.post<void>(`${this.baseUrl}/auth/logout`, null).pipe(
      catchError(() => of(void 0)),
      finalize(() => this.clearSession()),
    );
  }

  /**
   * Rehydrates the profile on boot when a token survived in `sessionStorage`.
   */
  restore(): Observable<MeResponse | null> {
    if (this.tokenState() === null) {
      return of(null);
    }

    return this.refreshMe().pipe(
      catchError(() => {
        this.clearSession();
        return of(null);
      }),
    );
  }

  refreshMe(): Observable<MeResponse> {
    return this.http.get<ApiEnvelope<MeResponse>>(`${this.baseUrl}/me`).pipe(
      map((response) => response.data),
      tap((data) => {
        this.userState.set(data.user);
        this.membershipsState.set(data.workspaces);
      }),
    );
  }

  requestPhoneOtp(phone: string): Observable<OtpRequestResult> {
    return this.http
      .post<ApiEnvelope<OtpRequestResult>>(`${this.baseUrl}/auth/otp/request`, { phone })
      .pipe(map((response) => response.data));
  }

  verifyPhone(phone: string, code: string): Observable<PhoneVerificationResult> {
    return this.http
      .post<ApiEnvelope<PhoneVerificationResult>>(`${this.baseUrl}/auth/otp/verify`, {
        phone,
        code,
      })
      .pipe(map((response) => response.data));
  }

  markPhoneVerified(phone: string, phoneVerifiedAt: string | null): void {
    const current = this.userState();

    if (!current) {
      return;
    }

    this.userState.set({
      ...current,
      phone,
      phone_verified_at: phoneVerifiedAt,
    });
  }

  clearSession(): void {
    this.tokenState.set(null);
    this.userState.set(null);
    this.membershipsState.set([]);
    removeStoredToken();
  }

  private applySession(data: AuthResponse): void {
    this.tokenState.set(data.token);
    this.userState.set(data.user);
    writeStoredToken(data.token);
  }
}
