<?php

namespace Tests\Feature;

use Tests\TestCase;

class KhongConTenCuTest extends TestCase
{
    public function test_tests_khong_con_tham_chieu_ten_cu_tieng_anh(): void
    {
        $ketQua = shell_exec(
            "git grep -nE 'App\\\\(Models|Services|Jobs|Mail|Exports)\\\\(Product|Category|Order|User|AuditLog|Medical|TriageReport|Drug|ChatMessage|ChatbotImage|ContactInquiry|Favorite|SearchQuery|Voice|MedicationReminder|ProductBatch|OrderItem|UserHealthProfile)[A-Za-z]*\b' -- tests/ 2>/dev/null"
        );

        $this->assertEmpty(trim((string) $ketQua), "Còn tham chiếu tên cũ:\n".$ketQua);
    }

    public function test_tests_khong_con_route_name_tieng_anh(): void
    {
        $ketQua = shell_exec(
            "git grep -nE \"route\('(home|contact|products|voice|cart|checkout|health|reminders|profile|favorites|dashboard|orders|inventory|chatSessions|triageReports|users|triage)\.\" -- tests/ 2>/dev/null"
        );

        $this->assertEmpty(trim((string) $ketQua), "Còn route name cũ:\n".$ketQua);
    }
}
