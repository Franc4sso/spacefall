<?php

use App\Game\Engine\TrustEngine;
use App\Game\Engine\RunState;

it('returns false when trust is above threshold', function () {
    $state = new RunState(day: 5, resources: [], flags: ['crew_trust' => 50]);
    expect((new TrustEngine)->shouldMutiny($state))->toBeFalse();
});

it('returns false when trust is exactly at threshold', function () {
    $state = new RunState(day: 5, resources: [], flags: ['crew_trust' => 20]);
    expect((new TrustEngine)->shouldMutiny($state))->toBeFalse();
});

it('returns true when trust is below 20 and crew alive', function () {
    $state = new RunState(
        day: 5, resources: [],
        flags: ['crew_trust' => 15],
        characters: [['name' => 'Ayaka', 'alive' => true, 'stress' => 40]],
    );
    expect((new TrustEngine)->shouldMutiny($state))->toBeTrue();
});

it('returns false when trust is low but no living crew', function () {
    $state = new RunState(
        day: 5, resources: [],
        flags: ['crew_trust' => 10],
        characters: [['name' => 'Ayaka', 'alive' => false, 'stress' => 90]],
    );
    expect((new TrustEngine)->shouldMutiny($state))->toBeFalse();
});

it('returns mutinyEventKey as mutiny_trigger', function () {
    expect((new TrustEngine)->mutinyEventKey())->toBe('mutiny_trigger');
});
