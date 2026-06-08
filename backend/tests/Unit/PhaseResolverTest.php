<?php

use App\Game\Engine\PhaseResolver;

function fullResources(): array
{
    // All resources comfortably above the critical threshold (20).
    return ['oxygen' => 90, 'food' => 90, 'power' => 90, 'morale' => 90, 'hull' => 90];
}

it('maps day bands to phases', function () {
    $r = new PhaseResolver();
    expect($r->resolve(1, fullResources(), 'isolation'))->toBe('isolation');
    expect($r->resolve(9, fullResources(), 'isolation'))->toBe('isolation');
    expect($r->resolve(10, fullResources(), 'isolation'))->toBe('deterioration');
    expect($r->resolve(20, fullResources(), 'isolation'))->toBe('deterioration');
    expect($r->resolve(21, fullResources(), 'isolation'))->toBe('reckoning');
    expect($r->resolve(40, fullResources(), 'isolation'))->toBe('reckoning');
});

it('does not advance on 2 critical resources (conservative)', function () {
    $r = new PhaseResolver();
    $res = fullResources();
    $res['oxygen'] = 10;
    $res['food'] = 10; // 2 critical
    expect($r->resolve(3, $res, 'isolation'))->toBe('isolation');
});

it('advances to deterioration on 3 critical resources', function () {
    $r = new PhaseResolver();
    $res = ['oxygen' => 10, 'food' => 10, 'power' => 10, 'morale' => 90, 'hull' => 90];
    expect($r->resolve(3, $res, 'isolation'))->toBe('deterioration');
});

it('advances to reckoning on 5 critical resources', function () {
    $r = new PhaseResolver();
    $res = ['oxygen' => 5, 'food' => 5, 'power' => 5, 'morale' => 5, 'hull' => 5];
    expect($r->resolve(3, $res, 'isolation'))->toBe('reckoning');
});

it('never drops below the floor (monotonic)', function () {
    $r = new PhaseResolver();
    expect($r->resolve(1, fullResources(), 'reckoning'))->toBe('reckoning');
});

it('takes the max of day, pressure, and floor', function () {
    $r = new PhaseResolver();
    expect($r->resolve(12, fullResources(), 'isolation'))->toBe('deterioration');
    $res = ['oxygen' => 5, 'food' => 5, 'power' => 5, 'morale' => 5, 'hull' => 5];
    expect($r->resolve(2, $res, 'isolation'))->toBe('reckoning');
});

it('exposes the index of a phase from config order', function () {
    $r = new PhaseResolver();
    expect($r->indexOf('isolation'))->toBe(0);
    expect($r->indexOf('deterioration'))->toBe(1);
    expect($r->indexOf('reckoning'))->toBe(2);
    expect($r->indexOf('bogus'))->toBe(0);
});
