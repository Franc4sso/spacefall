<?php

use App\Game\Engine\EpithetEngine;
use App\Game\Engine\RunState;

it('returns null when no clear pattern', function () {
    $state = new RunState(day: 5, resources: [], choiceLog: []);
    expect((new EpithetEngine)->calculate($state))->toBeNull();
});

it('returns il_generoso when 4+ generous tags', function () {
    $state = new RunState(day: 10, resources: [], choiceLog: [
        ['tags' => ['generous']], ['tags' => ['generous']],
        ['tags' => ['generous']], ['tags' => ['generous']],
    ]);
    expect((new EpithetEngine)->calculate($state))->toBe('il_generoso');
});

it('returns il_freddo when 4+ sacrifice_crew tags', function () {
    $state = new RunState(day: 10, resources: [], choiceLog: [
        ['tags' => ['sacrifice_crew']], ['tags' => ['sacrifice_crew']],
        ['tags' => ['sacrifice_crew']], ['tags' => ['sacrifice_crew']],
    ]);
    expect((new EpithetEngine)->calculate($state))->toBe('il_freddo');
});

it('returns l_imprudente when 4+ ignored_warning tags', function () {
    $state = new RunState(day: 10, resources: [], choiceLog: [
        ['tags' => ['ignored_warning']], ['tags' => ['ignored_warning']],
        ['tags' => ['ignored_warning']], ['tags' => ['ignored_warning']],
    ]);
    expect((new EpithetEngine)->calculate($state))->toBe('l_imprudente');
});

it('does not return epithet with only 3 tags', function () {
    $state = new RunState(day: 10, resources: [], choiceLog: [
        ['tags' => ['generous']], ['tags' => ['generous']], ['tags' => ['generous']],
    ]);
    expect((new EpithetEngine)->calculate($state))->toBeNull();
});
