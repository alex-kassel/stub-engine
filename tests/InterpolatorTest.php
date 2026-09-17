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

    public function test_interpolator_correctly_interpolates_tokens_with_hyphens_and_dots(): void
    {
        $interpolator = new Interpolator;

        $template = 'Package: {{ package-name }}, upper: {{ package-name | upper }}, domain: {{ app.domain }}. Keep original package-name text intact.';
        $unresolved = [];
        $result = $interpolator->interpolate(
            content: $template,
            tokens: [
                'package-name' => 'billing-module',
                'app.domain' => 'example.com',
            ],
            unresolved: $unresolved,
        );

        $this->assertSame([], $unresolved);
        $this->assertSame(
            'Package: billing-module, upper: BILLING-MODULE, domain: example.com. Keep original package-name text intact.',
            $result
        );
    }

    public function test_date_modifier_formats_valid_dates(): void
    {
        $interpolator = new Interpolator;

        $template = '{{ created_at | date:Y-m-d }}';
        $result = $interpolator->interpolate($template, ['created_at' => '2026-09-17 10:00:00']);
        $this->assertSame('2026-09-17', $result);
    }

    public function test_date_modifier_throws_exception_on_unparseable_date(): void
    {
        $interpolator = new Interpolator;

        $this->expectException(\InvalidArgumentException::class);
        $interpolator->interpolate('{{ created_at | date:Y-m-d }}', ['created_at' => 'not-a-valid-date']);
    }
}
