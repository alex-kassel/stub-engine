<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine\Tests;

use AlexKassel\StubEngine\DTOs\ScaffoldRequest;
use AlexKassel\StubEngine\Services\Interpolator;
use InvalidArgumentException;

class InterpolatorTest extends TestCase
{
    public function test_interpolator_resolves_delimiters_and_merged_tokens(): void
    {
        $interpolator = new Interpolator(
            open: '[[',
            close: ']]',
            tokens: [
                'app' => 'TestApp',
            ],
        );

        $this->assertSame('[[', $interpolator->open);
        $this->assertSame(']]', $interpolator->close);

        $merged = $interpolator->getMergedTokens(new ScaffoldRequest(source: 'memory', tokens: ['version' => '1.0']));
        $this->assertArrayHasKey('app', $merged);
        $this->assertArrayHasKey('version', $merged);
    }

    public function test_interpolator_handles_modifier_chains(): void
    {
        $interpolator = new Interpolator;

        $template = '{{ model | snake | upper }}';
        $result = $interpolator->interpolate($template, new ScaffoldRequest(source: 'memory', tokens: ['model' => 'orderItem']));
        $this->assertSame('ORDER_ITEM', $result);
    }

    public function test_interpolator_throws_on_unknown_modifier(): void
    {
        $interpolator = new Interpolator;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown token modifier [unknownModifier].');

        $interpolator->interpolate(
            '{{ model | unknownModifier }}',
            new ScaffoldRequest(source: 'memory', tokens: ['model' => 'Order'])
        );
    }

    public function test_interpolator_validates_token_keys_do_not_contain_pipe(): void
    {
        $interpolator = new Interpolator;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Token key [name|studly] cannot contain modifier pipe '|'.");

        $interpolator->getMergedTokens(new ScaffoldRequest(
            source: 'memory',
            tokens: ['name|studly' => 'User']
        ));
    }

    public function test_interpolator_validates_token_values_are_scalar(): void
    {
        $interpolator = new Interpolator;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Token [items] must be a string or scalar, [array] given.');

        $interpolator->getMergedTokens(new ScaffoldRequest(
            source: 'memory',
            tokens: ['items' => ['one', 'two']]
        ));
    }

    public function test_interpolator_registers_custom_modifiers(): void
    {
        $interpolator = new Interpolator;
        $interpolator->registerModifier('reverse', fn (string $val): string => strrev($val));

        $result = $interpolator->interpolate(
            '{{ word | reverse | upper }}',
            new ScaffoldRequest(source: 'memory', tokens: ['word' => 'hello'])
        );

        $this->assertSame('OLLEH', $result);
    }

    public function test_interpolator_correctly_interpolates_tokens_with_hyphens_and_dots(): void
    {
        $interpolator = new Interpolator;

        $template = 'Package: {{ package-name }}, upper: {{ package-name | upper }}, domain: {{ app.domain }}. Keep original package-name text intact.';
        $unresolved = [];
        $result = $interpolator->interpolate(
            content: $template,
            request: new ScaffoldRequest(
                source: 'memory',
                tokens: [
                    'package-name' => 'billing-module',
                    'app.domain' => 'example.com',
                ],
            ),
            unresolved: $unresolved,
        );

        $this->assertSame([], $unresolved);
        $this->assertSame(
            'Package: billing-module, upper: BILLING-MODULE, domain: example.com. Keep original package-name text intact.',
            $result
        );
    }

    public function test_interpolator_accepts_scaffold_request_and_extracts_tokens(): void
    {
        $interpolator = new Interpolator(
            tokens: ['company' => 'Acme'],
        );

        $scaffold = new ScaffoldRequest(
            source: 'stub.stub',
            tokens: ['name' => 'Widget'],
            openDelimiter: '<%',
            closeDelimiter: '%>',
        );

        $merged = $interpolator->getMergedTokens($scaffold);
        $this->assertSame(['company' => 'Acme', 'name' => 'Widget'], $merged);

        $rendered = $interpolator->interpolate('<% name %> by <% company %>', $scaffold);
        $this->assertSame('Widget by Acme', $rendered);

        $unresolved = [];
        $partiallyRendered = $interpolator->interpolate('<% name %> and <% missing | upper %>', $scaffold, unresolved: $unresolved);
        $this->assertSame(['<% missing | upper %>'], $unresolved);

        $extracted = $interpolator->extractTokens($partiallyRendered, '<%', '%>');
        $this->assertSame(['missing'], $extracted);
    }
}
