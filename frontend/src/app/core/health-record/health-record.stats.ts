import {
  Measurement,
  MeasurementType,
  measurementTypeLabel,
  measurementUnit,
} from './health-record.models';

const SEVEN_DAYS_MS = 7 * 24 * 60 * 60 * 1000;

export interface MeasurementStats {
  readonly count: number;
  readonly min: number;
  readonly max: number;
  readonly avg: number;
}

export interface MeasurementGroup {
  readonly type: MeasurementType;
  readonly label: string;
  readonly unit: string;
  readonly items: readonly Measurement[];
  readonly latest: Measurement;
  readonly stats7d: MeasurementStats | null;
}

function timestampOf(measurement: Measurement): number {
  if (measurement.recorded_at === null) {
    return 0;
  }

  const timestamp = Date.parse(measurement.recorded_at);

  return Number.isNaN(timestamp) ? 0 : timestamp;
}

/**
 * Groups readings by measurement type.
 *
 * The groups are ordered by their newest reading, and every group keeps its
 * readings newest-first. The 7-day summary is derived from the readings the
 * client currently holds (the list endpoint is cursor-paginated), so it never
 * invents points the server did not return.
 */
export function groupMeasurements(
  measurements: readonly Measurement[],
  now: number = Date.now(),
): readonly MeasurementGroup[] {
  const byType = new Map<MeasurementType, Measurement[]>();

  for (const measurement of measurements) {
    const items = byType.get(measurement.type) ?? [];
    items.push(measurement);
    byType.set(measurement.type, items);
  }

  const groups: MeasurementGroup[] = [];

  for (const [type, items] of byType) {
    const sorted = [...items].sort((left, right) => timestampOf(right) - timestampOf(left));
    const latest = sorted[0];

    if (latest === undefined) {
      continue;
    }

    const cutoff = now - SEVEN_DAYS_MS;
    const recent = sorted.filter((measurement) => timestampOf(measurement) >= cutoff);

    groups.push({
      type,
      label: measurementTypeLabel(type),
      unit: latest.unit !== '' ? latest.unit : measurementUnit(type),
      items: sorted,
      latest,
      stats7d: recent.length === 0 ? null : summarize(recent),
    });
  }

  groups.sort((left, right) => timestampOf(right.latest) - timestampOf(left.latest));

  return groups;
}

function summarize(measurements: readonly Measurement[]): MeasurementStats {
  let min = Number.POSITIVE_INFINITY;
  let max = Number.NEGATIVE_INFINITY;
  let total = 0;

  for (const measurement of measurements) {
    min = Math.min(min, measurement.value);
    max = Math.max(max, measurement.value);
    total += measurement.value;
  }

  return {
    count: measurements.length,
    min,
    max,
    avg: total / measurements.length,
  };
}
