<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\Triage\Ai\TriageAiContextService;
use App\Services\AuditLogService;
use Tests\TestCase;

class HonorificTest extends TestCase
{
    public function test_male_resolves_to_anh(): void
    {
        $this->assertSame('anh', TriageAiContextService::honorificFor('male'));
    }

    public function test_female_resolves_to_chi(): void
    {
        $this->assertSame('chị', TriageAiContextService::honorificFor('female'));
    }

    public function test_other_resolves_to_anh_chi(): void
    {
        $this->assertSame('anh/chị', TriageAiContextService::honorificFor('other'));
    }

    public function test_null_resolves_to_anh_chi(): void
    {
        $this->assertSame('anh/chị', TriageAiContextService::honorificFor(null));
    }
}
