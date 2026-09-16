<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine\Tests;

use AlexKassel\StubEngine\Engines\Interpolator;

class InterpolatorTest extends TestCase
{
    public function test_interpolator_resolves_delimiters_and_merged_tokens(): void
    {
        $interpolator = new Interpolator([
            'delimiters' => [
                'open' => '[[',
                'close' => ']]',
            ],
            'global_tokens' => [
                'app' => 'TestApp',
            ],
        ]);

        [$open, $close] = $interpolator->resolveDelimiters();
        $this->assertSame('[[', $open);
        $this->assertSame(']]', $close);

        $merged = $interpolator->getMergedTokens(['version' => '1.0']);
        $this->assertArrayHasKey('app', $merged);
        $this->assertArrayHasKey('version', $merged);
    }

    public function test_interpolator_handles_modifier_chains_and_parameterized_directives(): void
    {
        $interpolator = new Interpolator;

        $template = '{{ model | default:Order | snake | upper }}';
        $result = $interpolator->interpolate($template, ['model' => '']);
        $this->assertSame('ORDER', $result);
    }
}
