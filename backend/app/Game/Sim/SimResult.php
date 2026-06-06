<?php

namespace App\Game\Sim;

/**
 * The record of one simulated run: how it ended and the decision trail that
 * got it there.
 */
final class SimResult
{
    /**
     * @param  list<array{day:int,event:string,available_indices:list<int>,chosen:int,run_id:int}>  $steps
     */
    public function __construct(
        public int $seed,
        public string $policy,
        public int $day,
        public string $status,
        public ?string $endingKey,
        public ?string $endingType,
        public array $steps,
        public int $runId,
        public bool $diedOnChoice = false,
    ) {
    }

    public function won(): bool
    {
        return $this->endingType === 'win';
    }

    public function lost(): bool
    {
        return $this->endingType === 'lose';
    }

    /** The final decision step — the one that immediately preceded the ending. */
    public function lastStep(): ?array
    {
        return $this->steps === [] ? null : $this->steps[array_key_last($this->steps)];
    }
}
