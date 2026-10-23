const DEVICE_STORAGE_KEY = 'luckycare.device';

function randomSuffix(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID().replace(/-/g, '').slice(0, 10);
  }

  return Math.random().toString(36).slice(2, 12);
}

/**
 * Stable, non-sensitive device name used for Sanctum token naming.
 *
 * Persisted in `localStorage` so the same browser keeps one token family. It
 * deliberately contains no user, family or health information.
 */
function deviceStorage(): Storage | null {
  // Node's test environment exposes `window`/`document` but its `localStorage`
  // getter warns and throws without `--localstorage-file`. The web app has no
  // SSR target, so a Node process means "do not touch browser storage".
  const nodeProcess = (
    globalThis as { process?: { versions?: Record<string, string | undefined> } }
  ).process;

  if (typeof document === 'undefined' || nodeProcess?.versions?.['node'] !== undefined) {
    return null;
  }

  try {
    return window.localStorage;
  } catch {
    return null;
  }
}

export function getDeviceName(): string {
  try {
    const storage = deviceStorage();

    if (storage === null) {
      return `web-${randomSuffix()}`;
    }

    const existing = storage.getItem(DEVICE_STORAGE_KEY);

    if (existing) {
      return existing;
    }

    const generated = `web-${randomSuffix()}`;
    storage.setItem(DEVICE_STORAGE_KEY, generated);

    return generated;
  } catch {
    // Storage can be unavailable (private mode, blocked cookies). A stable
    // name is a convenience, never a correctness requirement.
    return `web-${randomSuffix()}`;
  }
}
