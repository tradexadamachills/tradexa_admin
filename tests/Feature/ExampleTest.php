<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_control_center_requires_staff_authentication(): void
    {
        $this->get('/')->assertRedirect('/login');
    }
}
