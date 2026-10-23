import { Routes } from '@angular/router';
import { authGuard } from './core/auth/auth.guard';
import { guestGuard } from './core/auth/guest.guard';

export const routes: Routes = [
  {
    path: 'login',
    title: 'เข้าสู่ระบบ | LuckyCare+',
    canActivate: [guestGuard],
    loadComponent: () => import('./features/auth/login/login.page').then((m) => m.LoginPage),
  },
  {
    path: 'register',
    title: 'สมัครสมาชิก | LuckyCare+',
    canActivate: [guestGuard],
    loadComponent: () =>
      import('./features/auth/register/register.page').then((m) => m.RegisterPage),
  },
  {
    // Phone verification is an authenticated onboarding step: the OTP
    // endpoints require a Bearer token (see .ai/07-API-CONTRACT.md §3.1).
    path: 'verify-phone',
    title: 'ยืนยันเบอร์โทรศัพท์ | LuckyCare+',
    canActivate: [authGuard],
    loadComponent: () =>
      import('./features/auth/verify-phone/verify-phone.page').then((m) => m.VerifyPhonePage),
  },
  {
    path: '',
    canActivate: [authGuard],
    loadComponent: () => import('./shared/layout/app-shell/app-shell').then((m) => m.AppShell),
    children: [
      { path: '', pathMatch: 'full', redirectTo: 'dashboard' },
      {
        path: 'dashboard',
        title: 'หน้าแรก | LuckyCare+',
        loadComponent: () =>
          import('./features/dashboard/dashboard.page').then((m) => m.DashboardPage),
      },
      {
        path: 'health',
        title: 'คลังสุขภาพ | LuckyCare+',
        data: { title: 'คลังสุขภาพ' },
        loadComponent: () => import('./features/health/health.page').then((m) => m.HealthPage),
      },
      {
        path: 'tasks',
        title: 'งานดูแล | LuckyCare+',
        data: { title: 'งานดูแล' },
        loadComponent: () => import('./features/tasks/tasks.page').then((m) => m.TasksPage),
      },
      {
        path: 'tasks/:taskId',
        title: 'รายละเอียดงาน | LuckyCare+',
        data: { title: 'รายละเอียดงาน' },
        loadComponent: () => import('./features/tasks/task-detail').then((m) => m.TaskDetail),
      },
      {
        path: 'appointments',
        title: 'นัดหมาย | LuckyCare+',
        data: { title: 'นัดหมาย' },
        loadComponent: () =>
          import('./features/placeholder/under-construction.page').then(
            (m) => m.UnderConstructionPage,
          ),
      },
      {
        path: 'family',
        title: 'ครอบครัวและสิทธิ์ | LuckyCare+',
        data: { title: 'ครอบครัวและสิทธิ์' },
        loadComponent: () =>
          import('./features/placeholder/under-construction.page').then(
            (m) => m.UnderConstructionPage,
          ),
      },
    ],
  },
  {
    path: '**',
    title: 'ไม่พบหน้า | LuckyCare+',
    loadComponent: () => import('./features/not-found/not-found.page').then((m) => m.NotFoundPage),
  },
];
