// Concurrent read-path load test for the deployed API (gap 3 in PROJECT-STATE).
//
// The in-process `perf:smoke` harness measures one sequential request at a time
// with a seeded tenant; this script measures the real HTTP stack with several
// clients at once. It is deliberately small: the product is a family app, so the
// realistic concurrency is a handful of caregivers, and every route is throttled
// per user (`throttle:60,1,<bucket>`), so the pacing below keeps a run inside the
// limits instead of measuring 429s.
//
// Usage (against the reference stack on the host):
//
//   docker run --rm -i \
//     -e BASE_URL=http://host.docker.internal:8080 \
//     -e LOAD_EMAIL=demo.owner@example.test \
//     -e LOAD_PASSWORD='DemoPass!2026' \
//     -e VUS=3 -e DURATION=60s \
//     grafana/k6 run - < backend/scripts/load/read-paths.js
//
// Refuses to guess credentials: LOAD_EMAIL/LOAD_PASSWORD are required.

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';

const BASE_URL = (__ENV.BASE_URL || 'http://localhost:8080').replace(/\/$/, '');
const EMAIL = __ENV.LOAD_EMAIL;
const PASSWORD = __ENV.LOAD_PASSWORD;
const VUS = Number(__ENV.VUS || 3);
const DURATION = __ENV.DURATION || '60s';

const dashboardDuration = new Trend('dashboard_duration', true);
const careTasksDuration = new Trend('care_tasks_duration', true);
const timelineDuration = new Trend('timeline_duration', true);
const throttled = new Rate('throttled');

export const options = {
  scenarios: {
    caregivers_reading: {
      executor: 'constant-vus',
      vus: VUS,
      duration: DURATION,
      gracefulStop: '5s',
    },
  },
  thresholds: {
    // A 429 is a failure here: it means the pacing is wrong, not that the
    // server is slow. The budgets mirror perf:smoke, so an HTTP regression is
    // comparable with the in-process baseline.
    http_req_failed: ['rate<0.01'],
    http_req_duration: ['p(95)<800'],
    dashboard_duration: ['p(95)<800'],
    care_tasks_duration: ['p(95)<300'],
    timeline_duration: ['p(95)<800'],
  },
};

function login() {
  if (!EMAIL || !PASSWORD) {
    throw new Error('LOAD_EMAIL and LOAD_PASSWORD are required (the demo credentials are in DEMO-DATA.md).');
  }

  const response = http.post(
    `${BASE_URL}/api/v1/auth/login`,
    JSON.stringify({ email: EMAIL, password: PASSWORD }),
    { headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, tags: { name: 'login' } },
  );

  const token = response.json('data.token');

  if (response.status !== 200 || !token) {
    throw new Error(`login failed: HTTP ${response.status}`);
  }

  return token;
}

export function setup() {
  const token = login();
  const auth = { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } };

  const me = http.get(`${BASE_URL}/api/v1/me`, { ...auth, tags: { name: 'me' } });
  const workspaces = me.json('data.workspaces') || [];
  const workspace = workspaces.find((item) => item.status === 'active');

  if (!workspace) {
    throw new Error('the account has no active workspace');
  }

  const profiles = http.get(`${BASE_URL}/api/v1/workspaces/${workspace.workspace_id}/profiles`, {
    ...auth,
    tags: { name: 'profiles' },
  });
  const profile = (profiles.json('data') || [])[0];

  // Warm-up, not measurement: the first request after a container restart pays
  // for PHP-FPM worker start and opcache cold misses (seconds, not milliseconds).
  // Without this the p95 of a short run is just the number of cold starts.
  http.get(`${BASE_URL}/api/v1/workspaces/${workspace.workspace_id}/dashboard`, {
    ...auth,
    tags: { name: 'warmup' },
  });
  http.get(`${BASE_URL}/api/v1/workspaces/${workspace.workspace_id}/care-tasks`, {
    ...auth,
    tags: { name: 'warmup' },
  });

  if (profile) {
    http.get(
      `${BASE_URL}/api/v1/workspaces/${workspace.workspace_id}/profiles/${profile.id}/timeline`,
      { ...auth, tags: { name: 'warmup' } },
    );
  }

  return {
    token,
    workspaceId: workspace.workspace_id,
    profileId: profile ? profile.id : null,
  };
}

function record(response, trend, name) {
  trend.add(response.timings.duration);
  throttled.add(response.status === 429);

  check(response, {
    [`${name} 2xx`]: (item) => item.status >= 200 && item.status < 300,
  });
}

// Three reads over ~6 s per virtual user: each route declares a 60/min bucket,
// so 3 VUs land at roughly 30 requests/min per route - inside the limit, which
// is the point (a load test that mostly measures the throttle proves nothing).
export default function (data) {
  const auth = { headers: { Authorization: `Bearer ${data.token}`, Accept: 'application/json' } };

  record(
    http.get(`${BASE_URL}/api/v1/workspaces/${data.workspaceId}/dashboard`, {
      ...auth,
      tags: { name: 'dashboard' },
    }),
    dashboardDuration,
    'dashboard',
  );
  sleep(2);

  record(
    http.get(`${BASE_URL}/api/v1/workspaces/${data.workspaceId}/care-tasks`, {
      ...auth,
      tags: { name: 'care-tasks' },
    }),
    careTasksDuration,
    'care-tasks',
  );
  sleep(2);

  if (data.profileId) {
    record(
      http.get(`${BASE_URL}/api/v1/workspaces/${data.workspaceId}/profiles/${data.profileId}/timeline`, {
        ...auth,
        tags: { name: 'timeline' },
      }),
      timelineDuration,
      'timeline',
    );
  }

  sleep(2);
}
