import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { BriefService } from './brief.service';

const PAYLOAD = {
  kind: 'doctor_brief',
  generated_at: '2026-10-24T16:00:00+00:00',
  period_from: '2026-09-24',
  period_to: '2026-10-24',
  sections: ['conditions'],
  profile: { name: 'สมชาย', birth_year: 1985 },
  conditions: [{ name: 'เบาหวาน', diagnosed_year: 2018 }],
  allergies: [],
  medications: [],
  vitals: [],
  appointments: [],
  care_tasks: { total_open: 0 },
};

describe('BriefService', () => {
  let httpMock: HttpTestingController;
  let service: BriefService;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });

    httpMock = TestBed.inject(HttpTestingController);
    service = TestBed.inject(BriefService);
  });

  afterEach(() => {
    httpMock.verify();
    TestBed.resetTestingModule();
  });

  it('BRF-PUB-T01 fetches the public brief by token and parses the frozen payload', async () => {
    const pending = service.fetch('token-1');

    const request = httpMock.expectOne(
      (item) => item.method === 'GET' && item.url === '/api/v1/briefs/token-1',
    );

    request.flush(PAYLOAD);

    const brief = await pending;

    expect(brief.profile?.name).toBe('สมชาย');
    expect(brief.periodFrom).toBe('2026-09-24');
    expect(brief.conditions.length).toBe(1);
  });

  it('BRF-PUB-T02 rejects a malformed brief body', async () => {
    const pending = service.fetch('token-1');

    httpMock
      .expectOne((item) => item.method === 'GET' && item.url === '/api/v1/briefs/token-1')
      .flush({ nope: true });

    await expect(pending).rejects.toThrow('malformed');
  });
});
