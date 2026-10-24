import { CareTask, TaskActionContext, availableActions, parseCareTask } from './caretask.models';

const CONTEXT: TaskActionContext = {
  currentUserId: '7',
  completedByUserId: '9',
};

function task(overrides: Record<string, unknown>): CareTask {
  const parsed = parseCareTask({
    id: 'task-1',
    profile_id: 'profile-1',
    profile_display_name: 'คุณพ่อ',
    tier: 'delegated',
    type: 'CUSTOM',
    title: 'งานทดสอบ',
    description: null,
    priority: 'normal',
    scheduled_for: null,
    due_at: null,
    timezone: null,
    status: 'assigned',
    outcome: null,
    verification: 'not_required',
    verification_required: false,
    evidence_policy: 'none',
    escalation_level: 0,
    blocked_reason: null,
    review_at: null,
    completed_at: null,
    version: 1,
    source: 'manual',
    created_by: null,
    assignments: [],
    created_at: null,
    updated_at: null,
    ...overrides,
  });

  if (parsed === null) {
    throw new Error('test fixture is not a care task');
  }

  return parsed;
}

describe('availableActions', () => {
  it('FE4-T06 offers only the transitions each tier allows', () => {
    expect(availableActions(task({ tier: 'routine', status: 'scheduled' }), CONTEXT)).toEqual([
      'complete',
      'cancel',
    ]);
    expect(availableActions(task({ tier: 'routine', status: 'completed' }), CONTEXT)).toEqual([]);

    expect(availableActions(task({ tier: 'delegated', status: 'assigned' }), CONTEXT)).toEqual([
      'accept',
      'decline',
      'block',
      'cancel',
    ]);
    expect(availableActions(task({ tier: 'delegated', status: 'in_progress' }), CONTEXT)).toEqual([
      'complete',
      'block',
      'cancel',
    ]);
    expect(availableActions(task({ tier: 'delegated', status: 'completed' }), CONTEXT)).toEqual([
      'reopen',
    ]);

    expect(availableActions(task({ tier: 'clinical', status: 'open' }), CONTEXT)).toEqual([
      'cancel',
    ]);
    expect(availableActions(task({ tier: 'clinical', status: 'assigned' }), CONTEXT)).toEqual([
      'start',
      'cancel',
    ]);
    expect(availableActions(task({ tier: 'clinical', status: 'in_progress' }), CONTEXT)).toEqual([
      'complete',
      'cancel',
    ]);
  });

  it('FE4-T06 hides verify/reject from the member who completed the task', () => {
    const pending = {
      tier: 'clinical',
      status: 'completed',
      verification: 'pending',
    };

    expect(availableActions(task(pending), { currentUserId: '7', completedByUserId: '9' })).toEqual(
      ['verify', 'reject'],
    );

    expect(availableActions(task(pending), { currentUserId: '7', completedByUserId: '7' })).toEqual(
      [],
    );
  });

  it('FE4-T06 a safe card never offers an action', () => {
    const card = parseCareTask({
      id: 'task-card',
      type: 'CUSTOM',
      title: 'งานที่เห็นเฉพาะข้อมูลจําเป็น',
      due_at: null,
      status: 'assigned',
      priority: 'normal',
      profile_display_name: 'คุณพ่อ',
      version: 2,
    });

    if (card === null) {
      throw new Error('test fixture is not a care task');
    }

    expect(card.kind).toBe('card');
    expect(availableActions(card, CONTEXT)).toEqual([]);
  });
});
