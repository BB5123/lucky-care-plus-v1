<?php

namespace App\Modules\Retention\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The email half of the 30/7/1-day retention notice (R7).
 *
 * The mail carries no health data and no name: the account is addressed as
 * "บัญชีของท่าน" and only the deadline is named. That is deliberate - the one
 * thing this message must survive is the recipient reading it, so it must not
 * put a diagnosis, a medication, a person's name or a document title in an
 * inbox, a mail log, or a bounce report (N-02).
 *
 * The copy is product copy, so it is Thai (repository rule).
 */
final class RetentionNoticeMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /** @var array<string, string> */
    private const SUBJECTS = [
        'retention.notice_30' => 'บัญชีของคุณจะถูกลบในอีก 30 วัน',
        'retention.notice_7' => 'บัญชีของคุณจะถูกลบในอีก 7 วัน',
        'retention.notice_1' => 'บัญชีของคุณจะถูกลบในอีก 1 วัน',
    ];

    /** @var array<string, string> */
    private const BODIES = [
        'retention.notice_30' => 'บัญชีของท่านอยู่ในคิวการลบ และจะถูกลบในอีก 30 วัน '
            .'ท่านสามารถส่งออกข้อมูลหรือกู้คืนบัญชีได้จากแอป Lucky Care Plus '
            .'หากไม่ดำเนินการใด ๆ เราจะแจ้งเตือนอีกครั้งก่อนถึงกำหนด 7 วัน และ 1 วัน '
            .'บันทึกการเข้าถึงและการลบข้อมูลจะถูกเก็บไว้เพื่อการตรวจสอบ โดยไม่มีข้อมูลสุขภาพ',
        'retention.notice_7' => 'บัญชีของท่านจะถูกลบในอีก 7 วัน '
            .'ท่านสามารถส่งออกข้อมูลหรือกู้คืนบัญชีได้จากแอป Lucky Care Plus '
            .'หากไม่ดำเนินการใด ๆ เราจะแจ้งเตือนอีกครั้งก่อนถึงกำหนด 1 วัน '
            .'บันทึกการเข้าถึงและการลบข้อมูลจะถูกเก็บไว้เพื่อการตรวจสอบ โดยไม่มีข้อมูลสุขภาพ',
        'retention.notice_1' => 'บัญชีของท่านจะถูกลบในอีก 1 วัน '
            .'นี่คือการแจ้งเตือนครั้งสุดท้าย หากต้องการเก็บข้อมูลไว้ กรุณาส่งออกข้อมูลหรือกู้คืนบัญชีจากแอปทันที '
            .'บันทึกการเข้าถึงและการลบข้อมูลจะถูกเก็บไว้เพื่อการตรวจสอบ โดยไม่มีข้อมูลสุขภาพ',
    ];

    public function __construct(public readonly string $noticeKey) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: self::SUBJECTS[$this->noticeKey] ?? 'แจ้งเตือนกำหนดการลบบัญชี',
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: '<p>'.(self::BODIES[$this->noticeKey] ?? '').'</p>',
        );
    }

    public function noticeSubject(): string
    {
        return self::SUBJECTS[$this->noticeKey] ?? 'แจ้งเตือนกำหนดการลบบัญชี';
    }
}
