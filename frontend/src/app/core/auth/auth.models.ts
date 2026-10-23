export interface AuthUser {
  id: string;
  name: string;
  email: string;
  phone: string | null;
  phone_verified_at: string | null;
}

export interface MembershipSummary {
  id: string;
  workspace_id: string;
  membership_id: string;
  name: string;
  role: string;
  status: string;
}

export interface AuthResponse {
  user: AuthUser;
  token: string;
  token_type: string;
}

export interface MeResponse {
  user: AuthUser;
  workspaces: MembershipSummary[];
}

export interface LoginRequest {
  email: string;
  password: string;
  device_name?: string;
}

export interface RegisterRequest {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
  device_name?: string;
}

export interface OtpRequestResult {
  channel: string;
  expires_in: number;
  message: string;
  demo_mode?: boolean;
  demo_code?: string;
}

export interface PhoneVerificationResult {
  phone: string;
  phone_verified_at: string | null;
}

export interface ApiEnvelope<T> {
  data: T;
}
