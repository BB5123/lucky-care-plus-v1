/**
 * Thai relative time for "อัปเดตเมื่อ ...".
 *
 * Deliberately hand-written instead of `Intl.RelativeTimeFormat` so the
 * sentence stays short and predictable in Thai.
 */
export function formatRelativeThai(isoTimestamp: string | null, now: number): string | null {
  if (isoTimestamp === null || isoTimestamp === '') {
    return null;
  }

  const timestamp = Date.parse(isoTimestamp);

  if (Number.isNaN(timestamp)) {
    return null;
  }

  const elapsedSeconds = Math.max(0, Math.floor((now - timestamp) / 1000));

  if (elapsedSeconds < 60) {
    return 'เมื่อสักครู่';
  }

  const minutes = Math.floor(elapsedSeconds / 60);

  if (minutes < 60) {
    return `${minutes} นาทีที่แล้ว`;
  }

  const hours = Math.floor(minutes / 60);

  if (hours < 24) {
    return `${hours} ชั่วโมงที่แล้ว`;
  }

  const days = Math.floor(hours / 24);

  return `${days} วันที่แล้ว`;
}
