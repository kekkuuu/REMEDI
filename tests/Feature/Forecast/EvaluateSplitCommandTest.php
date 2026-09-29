<?php

namespace Tests\Feature\Forecast;

use Tests\TestCase;

/**
 * forecast:evaluate-split refuses a ratio that is not a sensible train share
 * BEFORE shelling out to Python -- so these run without Python or MySQL.
 */
class EvaluateSplitCommandTest extends TestCase
{
    public function test_ratio_outside_the_range_is_refused(): void
    {
        foreach (['2', '0.3', '0.99', 'abc'] as $ratio) {
            $this->artisan('forecast:evaluate-split', ['--ratio' => $ratio])
                ->expectsOutputToContain('--ratio must be a number between 0.5 and 0.95')
                ->assertFailed();
        }
    }

    public function test_the_script_it_runs_exists(): void
    {
        $this->assertFileExists(resource_path('python/evaluate_train_test_split.py'));
    }
}
