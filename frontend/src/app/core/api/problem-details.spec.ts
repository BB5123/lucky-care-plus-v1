import { HttpErrorResponse } from '@angular/common/http';
import { toUserMessage } from './problem-details';

describe('toUserMessage', () => {
  it('FE-T05 prefers the safe Thai message returned by the API', () => {
    const error = new HttpErrorResponse({
      status: 422,
      error: {
        code: 'validation',
        message: 'อีเมลหรือรหัสผ่านไม่ถูกต้อง',
        detail: 'Invalid credentials',
      },
    });

    expect(toUserMessage(error)).toBe('อีเมลหรือรหัสผ่านไม่ถูกต้อง');
  });

  it('FE-T05 uses the API detail when no message is present', () => {
    const error = new HttpErrorResponse({
      status: 403,
      error: {
        code: 'forbidden',
        detail: 'ไม่ได้รับอนุญาตให้ดําเนินการนี้',
      },
    });

    expect(toUserMessage(error)).toBe('ไม่ได้รับอนุญาตให้ดําเนินการนี้');
  });

  it('FE-T05 falls back to a generic Thai sentence', () => {
    expect(toUserMessage(new Error('boom'))).toBe('เกิดข้อผิดพลาดบางอย่าง กรุณาลองใหม่อีกครั้ง');
    expect(toUserMessage(null)).toBe('เกิดข้อผิดพลาดบางอย่าง กรุณาลองใหม่อีกครั้ง');
  });

  it('FE-T05 never leaks a stack trace', () => {
    const error = new HttpErrorResponse({
      status: 500,
      error: {
        message:
          'TypeError: Cannot read properties of undefined\n    at Foo.bar (/app/src/foo.ts:12:5)',
      },
    });

    const message = toUserMessage(error);

    expect(message).toBe('เกิดข้อผิดพลาดบางอย่าง กรุณาลองใหม่อีกครั้ง');
    expect(message).not.toContain('TypeError');
    expect(message).not.toContain('at Foo.bar');
  });
});
