<?php

namespace Tests\Unit;

use App\Models\Track;
use Tests\TestCase;

class TrackTest extends TestCase
{
    public function test_peaks_ready_is_false_when_peaks_null(): void
    {
        $track = new Track(['peaks' => null]);

        $this->assertFalse($track->peaks_ready);
    }

    public function test_peaks_ready_is_true_when_peaks_set(): void
    {
        $track = new Track(['peaks' => ['channels' => [[0.1, -0.1]]]]);

        $this->assertTrue($track->peaks_ready);
    }
}
