<?php

declare(strict_types=1);

/**
 * Pins supported registry key shapes that stricter validation must not reject.
 *
 * PHP arrays can convert numeric-looking keys to integers, while aliases can also
 * be empty strings. These are distinct from malformed classes or policy values.
 * The tests round-trip registered values and inspect registry output so validation
 * changes preserve supported configuration instead of inventing new restrictions.
 */

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Scarlett\Configuration;
use Scarlett\Inputs\ModelReference;
use Scarlett\Inputs\PayloadCodec;
use Scarlett\Policies\RateLimit;
use Scarlett\States\Running;
use Scarlett\States\StateRegistry;
use Scarlett\Tests\Fixtures\AliasedState;
use Scarlett\Tests\Fixtures\Amount;
use Scarlett\Tests\Fixtures\AmountMapper;
use Scarlett\Tests\Fixtures\Approved;
use Scarlett\Tests\Fixtures\SourceRecord;
use Scarlett\Tests\Fixtures\StringStatus;

it('accepts numeric identifiers in enum, model and throttle configuration', function () {
    $original = [
        'scarlett.enums' => config('scarlett.enums'),
        'scarlett.models' => config('scarlett.models'),
        'scarlett.payload_mappers' => config('scarlett.payload_mappers'),
        'scarlett.throttles' => config('scarlett.throttles'),
    ];

    try {
        config([
            'scarlett.enums' => [0 => StringStatus::class],
            'scarlett.models' => [0 => SourceRecord::class],
            'scarlett.payload_mappers' => [0 => AmountMapper::class],
            'scarlett.throttles' => [0 => ['max_requests' => 1, 'per_seconds' => 60]],
        ]);

        app(Configuration::class)->validate();

        $codec = app(PayloadCodec::class);
        Schema::create('source_records', fn (Blueprint $table) => [$table->id(), $table->string('name')]);
        $source = SourceRecord::create(['name' => 'numeric-alias']);
        $restored = $codec->decode($codec->encode(new ModelReference('0', $source->id)));
        expect($codec->decode($codec->encode(StringStatus::Done)))->toBe(StringStatus::Done)
            ->and($codec->decode($codec->encode(new Amount(125)))->minorUnits)->toBe(125)
            ->and($restored->name)->toBe('numeric-alias')
            ->and((new RateLimit('0', 'numeric-key'))->maxRequests)->toBe(1);
    } finally {
        config($original);
    }
});

it('accepts empty enum and model aliases', function () {
    $original = [
        'scarlett.enums' => config('scarlett.enums'),
        'scarlett.models' => config('scarlett.models'),
    ];

    try {
        config([
            'scarlett.enums' => ['' => StringStatus::class],
            'scarlett.models' => ['' => SourceRecord::class],
        ]);

        app(Configuration::class)->validate();

        expect(app(PayloadCodec::class)->decode([
            'version' => 2,
            'value' => ['type' => 'enum', 'alias' => '', 'data' => StringStatus::Done->value],
        ]))->toBe(StringStatus::Done);
    } finally {
        config($original);
    }
});

it('accepts associative outer state and transition configuration keys', function () {
    $original = [
        'scarlett.states' => config('scarlett.states'),
        'scarlett.transitions' => config('scarlett.transitions'),
    ];

    try {
        config([
            'scarlett.states' => ['approved' => Approved::class],
            'scarlett.transitions' => ['approved' => [Running::class, Approved::class]],
        ]);

        $registry = app(StateRegistry::class);
        expect($registry->classes())->toContain(Approved::class)
            ->and($registry->transitions())->toContain([Running::class, Approved::class]);
    } finally {
        config($original);
    }
});

it('accepts an empty custom state alias because stored rows keep the alias exactly', function () {
    AliasedState::$name = '';

    try {
        config(['scarlett.states' => [AliasedState::class]]);

        expect((new StateRegistry)->classes())->toContain(AliasedState::class)
            ->and(AliasedState::getMorphClass())->toBe('');
    } finally {
        AliasedState::$name = null;
    }
});
