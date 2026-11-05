<?php

namespace Tests\Feature;

use Tests\TestCase;

class ChartComponentTest extends TestCase
{
    public function test_chart_draws_real_values_and_labels_the_period(): void
    {
        $view = $this->blade('<x-chart title="User registrations" :days="$days" :series="$series"/>', [
            'days' => ['2026-11-01', '2026-11-02', '2026-11-03'],
            'series' => [['label' => 'Registrations', 'color' => '#1f5fe0', 'values' => [1, 4, 2]]],
        ]);
        $view->assertSee('User registrations');
        $view->assertSee('polyline', false);
        $view->assertSee('2026-11-01');
        $view->assertSee('2026-11-03');
    }

    public function test_chart_says_so_when_there_is_no_data(): void
    {
        $view = $this->blade('<x-chart title="Trading activity" :days="$days" :series="$series"/>', [
            'days' => ['2026-11-01', '2026-11-02'],
            'series' => [['label' => 'Trades', 'color' => '#1f5fe0', 'values' => [0, 0]]],
        ]);
        $view->assertSee('No data in this period yet.');
        $view->assertDontSee('polyline', false);
    }
}
