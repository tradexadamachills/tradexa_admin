<?php

namespace Tests\Unit;

use App\Services\DiditReviewService;
use PHPUnit\Framework\TestCase;

class ProviderReviewTest extends TestCase
{
    public function test_approval_needs_all_three_successful_feature_arrays(): void
    {
        $service = new DiditReviewService;
        $good = ['status' => 'Approved', 'id_verifications' => [['status' => 'Approved']], 'liveness_checks' => [['status' => 'Approved']], 'face_matches' => [['status' => 'Approved']]];
        $this->assertTrue($service->passes($good));
        foreach (['id_verifications', 'liveness_checks', 'face_matches'] as $key) {
            foreach ([null, [], [false], [['status' => []]], [['status' => 'Declined']], [['status' => 'Approved'], ['status' => 'In Review']]] as $value) {
                $bad = $good;
                $bad[$key] = $value;
                $this->assertFalse($service->passes($bad));
            }
        }
        $good['status_override'] = ['automatic_status' => 'Declined'];
        $this->assertFalse($service->passes($good));
    }
}
