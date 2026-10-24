import { Measurement, measurementUnit } from './health-record.models';

/**
 * Thai formatting for Health Vault values.
 *
 * Dates always go through `Intl.DateTimeFormat('th-TH')`, so the Buddhist era
 * and Thai month names come from the platform instead of hand-written tables.
 */
const dateTimeFormatter = new Intl.DateTimeFormat('th-TH', {
  dateStyle: 'medium',
  timeStyle: 'short',
});

const dateFormatter = new Intl.DateTimeFormat('th-TH', {
  dateStyle: 'medium',
});

const numberFormatter = new Intl.NumberFormat('th-TH', {
  maximumFractionDigits: 2,
});

function parseTimestamp(value: string | null): number | null {
  if (value === null || value === '') {
    return null;
  }

  // A date-only value is a calendar date, not an instant; parse it locally so
  // a negative UTC offset cannot shift the displayed day.
  const normalized = /^\d{4}-\d{2}-\d{2}$/.test(value) ? `${value}T00:00:00` : value;
  const timestamp = Date.parse(normalized);

  return Number.isNaN(timestamp) ? null : timestamp;
}

export function formatThaiDateTime(value: string | null): string {
  const timestamp = parseTimestamp(value);

  if (timestamp === null) {
    return 'ไม่ระบุเวลา';
  }

  return dateTimeFormatter.format(new Date(timestamp));
}

export function formatThaiDate(value: string | null): string {
  const timestamp = parseTimestamp(value);

  if (timestamp === null) {
    return 'ไม่ระบุวันที่';
  }

  return dateFormatter.format(new Date(timestamp));
}

export function formatHealthNumber(value: number | null): string {
  if (value === null || !Number.isFinite(value)) {
    return '—';
  }

  return numberFormatter.format(value);
}

export function formatMeasurementValue(measurement: Measurement): string {
  const primary = formatHealthNumber(measurement.value);

  if (measurement.type === 'blood_pressure' && measurement.secondary_value !== null) {
    return `${primary}/${formatHealthNumber(measurement.secondary_value)}`;
  }

  return primary;
}

export function measurementDisplayUnit(measurement: Measurement): string {
  return measurement.unit !== '' ? measurement.unit : measurementUnit(measurement.type);
}
