import {
  Appointment,
  appointmentDocumentProgress,
  appointmentRelativeLabel,
  availableAppointmentActions,
  parseAppointment,
  splitAppointments,
} from './appointment.models';

function appointmentPayload(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    id: 'appointment-1',
    profile_id: 'profile-1',
    profile_display_name: 'คุณพ่อ',
    title: 'ตรวจติดตามโรคความดัน',
    provider_name: 'นพ.สมชาย',
    facility: 'โรงพยาบาลกรุงเทพ',
    purpose: 'ติดตามความดันโลหิต',
    location: 'อาคาร A ชั้น 3',
    scheduled_at: '2026-11-05T09:00:00+07:00',
    duration_minutes: 30,
    status: 'planned',
    cancelled_reason: null,
    rescheduled_from_id: null,
    transported_by_task_id: null,
    prepared_documents: [
      { label: 'บัตรประชาชน', done: false },
      { label: 'สมุดสุขภาพ', done: true },
    ],
    follow_up_task_id: null,
    version: 1,
    source: 'manual',
    created_by: 1,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

function appointment(overrides: Record<string, unknown> = {}): Appointment {
  const parsed = parseAppointment(appointmentPayload(overrides));

  if (parsed === null) {
    throw new Error('test fixture is not an appointment');
  }

  return parsed;
}

describe('appointment models', () => {
  it('FE6-T02 splits upcoming/past and sorts upcoming soonest-first', () => {
    const now = new Date('2026-10-24T10:00:00+07:00');

    const soon = appointment({
      id: 'soon',
      scheduled_at: '2026-10-26T09:00:00+07:00',
    });
    const soonest = appointment({
      id: 'soonest',
      scheduled_at: '2026-10-25T09:00:00+07:00',
    });
    const later = appointment({
      id: 'later',
      scheduled_at: '2026-11-10T09:00:00+07:00',
    });
    const attended = appointment({
      id: 'attended',
      status: 'attended',
      scheduled_at: '2026-10-20T09:00:00+07:00',
    });
    // A cancelled future row is history, not "upcoming".
    const cancelled = appointment({
      id: 'cancelled',
      status: 'cancelled',
      scheduled_at: '2026-10-30T09:00:00+07:00',
    });

    const split = splitAppointments([soon, later, attended, soonest, cancelled], now);

    expect(split.upcoming.map((item) => item.id)).toEqual(['soonest', 'soon', 'later']);
    expect(split.past.map((item) => item.id)).toEqual(['cancelled', 'attended']);
  });

  it('FE6-T06 offers exactly the transitions the state machine allows', () => {
    expect(availableAppointmentActions('planned')).toEqual(['confirm', 'reschedule', 'cancel']);
    expect(availableAppointmentActions('confirmed')).toEqual(['attended', 'reschedule', 'cancel']);
    expect(availableAppointmentActions('attended')).toEqual([]);
    expect(availableAppointmentActions('rescheduled')).toEqual([]);
    expect(availableAppointmentActions('cancelled')).toEqual([]);
    expect(availableAppointmentActions('missed')).toEqual([]);
    expect(availableAppointmentActions('needs_followup')).toEqual([]);
  });

  it('labels upcoming days relatively and counts prepared documents', () => {
    const now = new Date('2026-10-24T10:00:00+07:00');

    expect(appointmentRelativeLabel('2026-10-27T09:00:00+07:00', now)).toBe('อีก 3 วัน');
    expect(appointmentRelativeLabel('2026-10-24T18:00:00+07:00', now)).toBe('วันนี้');
    expect(appointmentRelativeLabel('2026-10-25T09:00:00+07:00', now)).toBe('พรุ่งนี้');
    expect(appointmentRelativeLabel('2026-10-21T09:00:00+07:00', now)).toBe('3 วันที่แล้ว');

    expect(appointmentDocumentProgress(appointment())).toBe('เอกสาร 1/2');
  });
});
