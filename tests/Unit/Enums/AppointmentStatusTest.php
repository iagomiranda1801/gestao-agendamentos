<?php

namespace Tests\Unit\Enums;

use App\Enums\AppointmentStatus;
use PHPUnit\Framework\TestCase;

class AppointmentStatusTest extends TestCase
{
    public function test_confirmed_badge_uses_warning_color(): void
    {
        $this->assertSame('warning', AppointmentStatus::Confirmed->color());
        $this->assertSame('Confirmado', AppointmentStatus::Confirmed->label());
    }

    public function test_other_status_badge_colors_remain_unchanged(): void
    {
        $this->assertSame('warning', AppointmentStatus::Pending->color());
        $this->assertSame('info', AppointmentStatus::InProgress->color());
        $this->assertSame('success', AppointmentStatus::Completed->color());
        $this->assertSame('gray', AppointmentStatus::Cancelled->color());
        $this->assertSame('danger', AppointmentStatus::NoShow->color());
    }
}
