<?php

namespace App\Console\Commands;

use App\Game\Sim\GreedySurvivalPolicy;
use App\Game\Sim\Policy;
use App\Game\Sim\RandomPolicy;
use App\Game\Sim\Simulator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Headless balance harness. Plays N seeded runs under a policy and prints an
 * aggregate report: run-length distribution, win/loss rates, and which endings
 * were reached. Surprising output = a balance bug, fixed in DATA (weights /
 * thresholds / content), not engine code (design §5).
 *
 *   php artisan sim:run --count=5000 --policy=greedy_survival
 *   php artisan sim:run --count=5000 --policy=random --items=welder,scanner,seedbank,comms,medkit
 */
class SimRun extends Command
{
    protected $signature = 'sim:run
        {--count=1000 : number of runs}
        {--policy=greedy_survival : random|greedy_survival}
        {--items= : comma-separated item keys (else a fixed survival kit)}
        {--seed=0 : base seed (each run uses base+i)}
        {--memory : run against a fresh in-memory SQLite DB (much faster; leaves the file DB untouched)}';

    protected $description = 'Simulate many runs and report the balance distribution.';

    public function handle(Simulator $sim): int
    {
        if ($this->option('memory')) {
            $this->useInMemoryDatabase();
        }

        $count = (int) $this->option('count');
        $policy = $this->resolvePolicy($this->option('policy'));
        $items = $this->resolveItems($this->option('items'));
        $base = (int) $this->option('seed');

        $this->info("Simulating {$count} runs · policy={$policy->name()} · items=[" . implode(',', $items) . ']');

        $lengths = [];
        $wins = 0;
        $losses = 0;
        $stalls = 0;
        $endings = [];

        $bar = $this->output->createProgressBar($count);
        for ($i = 0; $i < $count; $i++) {
            $r = $sim->play($base + $i, $policy, $items);
            $lengths[] = $r->day;
            if ($r->won()) {
                $wins++;
            } elseif ($r->lost()) {
                $losses++;
            } else {
                $stalls++;
            }
            $key = $r->endingKey ?? '(none)';
            $endings[$key] = ($endings[$key] ?? 0) + 1;
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);

        sort($lengths);
        $this->line('Run length (days):');
        $this->line(sprintf('  min %d · median %d · p90 %d · max %d',
            $lengths[0], $this->pct($lengths, 50), $this->pct($lengths, 90), end($lengths)));
        $this->newLine();

        $this->line(sprintf('Outcomes: wins %d (%.1f%%) · losses %d (%.1f%%) · stalls %d',
            $wins, 100 * $wins / $count, $losses, 100 * $losses / $count, $stalls));
        $this->newLine();

        $this->line('Endings reached:');
        arsort($endings);
        foreach ($endings as $key => $n) {
            $this->line(sprintf('  %-22s %5d  (%.1f%%)', $key, $n, 100 * $n / $count));
        }

        if ($stalls > 0) {
            $this->error("WARNING: {$stalls} runs stalled (no card) — that's a bug.");
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Point the default connection at a fresh in-memory SQLite database and
     * build the schema + seed content into it. The on-disk file DB is left
     * untouched. This avoids ~30k fsync'd round-trips per run (the sim reloads
     * the run several times per simulated day), making large sims ~10-50x faster.
     */
    private function useInMemoryDatabase(): void
    {
        $this->info('Using in-memory SQLite (file DB untouched).');

        config(['database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');

        Artisan::call('migrate', ['--force' => true, '--database' => 'sqlite']);
        Artisan::call('db:seed', ['--force' => true, '--database' => 'sqlite']);
    }

    private function resolvePolicy(string $name): Policy
    {
        return match ($name) {
            'random' => new RandomPolicy(),
            'greedy_survival' => new GreedySurvivalPolicy(),
            default => throw new \InvalidArgumentException("Unknown policy: {$name}"),
        };
    }

    /** @return list<string> */
    private function resolveItems(?string $csv): array
    {
        if ($csv) {
            return array_values(array_filter(array_map('trim', explode(',', $csv))));
        }
        // A reasonable survival kit when none is specified.
        return ['welder', 'scanner', 'seedbank', 'comms', 'medkit'];
    }

    /** @param list<int> $sorted */
    private function pct(array $sorted, int $p): int
    {
        $idx = (int) floor(($p / 100) * (count($sorted) - 1));
        return $sorted[$idx];
    }
}
