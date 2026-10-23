/**
 * Thai copy for timeline entries.
 *
 * A timeline entry is rendered from its `summary_key`, never from a server
 * sentence. This dictionary is the single place where those keys become human
 * text; an unknown key falls back to a neutral sentence instead of exposing the
 * raw key.
 */
export const TIMELINE_SUMMARY_FALLBACK = 'มีเหตุการณ์ใหม่ในไทม์ไลน์';

const TIMELINE_SUMMARY_COPY: Readonly<Record<string, string>> = {
  'timeline.measurement.recorded': 'บันทึกค่าที่วัดใหม่',
  'timeline.condition.recorded': 'บันทึกโรคประจําตัว',
  'timeline.allergy.recorded': 'บันทึกการแพ้ยา',
  'timeline.medication_plan.created': 'เริ่มแผนยาใหม่',
  'timeline.medication_plan.discontinued': 'หยุดแผนยา',
  'timeline.medication_record.recorded': 'บันทึกการกินยา',
  'timeline.care_task.created': 'สร้างงานดูแลใหม่',
  'timeline.care_task.assigned': 'มอบหมายงานดูแล',
  'timeline.care_task.accepted': 'รับงานดูแลแล้ว',
  'timeline.care_task.declined': 'ปฏิเสธงานดูแล',
  'timeline.care_task.started': 'เริ่มงานดูแล',
  'timeline.care_task.unblocked': 'แก้ไขงานที่ติดขัดแล้ว',
  'timeline.care_task.completed': 'งานดูแลเสร็จแล้ว',
  'timeline.care_task.verified': 'ตรวจสอบงานดูแลแล้ว',
  'timeline.care_task.rejected': 'ส่งงานดูแลกลับให้แก้ไข',
  'timeline.care_task.reopened': 'เปิดงานดูแลอีกครั้ง',
  'timeline.care_task.cancelled': 'ยกเลิกงานดูแล',
  'timeline.care_task.blocked': 'งานดูแลติดขัด',
  'timeline.care_task.updated': 'อัปเดตงานดูแล',
  'timeline.appointment.created': 'สร้างนัดหมายใหม่',
  'timeline.appointment.attended': 'เข้ารับการนัดหมายแล้ว',
};

export function timelineSummaryLabel(key: string | null): string {
  if (key === null || key === '') {
    return TIMELINE_SUMMARY_FALLBACK;
  }

  return TIMELINE_SUMMARY_COPY[key] ?? TIMELINE_SUMMARY_FALLBACK;
}
