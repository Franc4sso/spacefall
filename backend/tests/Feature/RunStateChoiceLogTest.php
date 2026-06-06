<?php

use App\Game\Engine\RunState;
use App\Models\Run;

it('loads choice_log from run model', function () {
    $run = new Run();
    $run->forceFill([
        'day' => 1,
        'resources' => ['oxygen' => 100],
        'choice_log' => [['day' => 1, 'event_key' => 'foo', 'choice_index' => 0, 'tags' => []]],
    ]);
    $state = RunState::fromRun($run);
    expect($state->choiceLog)->toHaveCount(1);
    expect($state->choiceLog[0]['event_key'])->toBe('foo');
});

it('writes choice_log back to run', function () {
    $run = new Run();
    $run->forceFill(['day' => 1, 'resources' => [], 'choice_log' => []]);
    $state = RunState::fromRun($run);
    $state->choiceLog[] = ['day' => 2, 'event_key' => 'bar', 'choice_index' => 1, 'tags' => ['risk']];
    $state->applyTo($run);
    expect($run->choice_log)->toHaveCount(1);
});
