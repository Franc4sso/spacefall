<?php

namespace App\Http\Controllers;

use App\Game\DayProcessor;
use App\Game\RunFactory;
use App\Models\Run;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RunController extends Controller
{
    public function __construct(
        private readonly RunFactory $factory,
        private readonly DayProcessor $dayProcessor,
    ) {
    }

    /**
     * POST /api/runs — start a run.
     *
     * Body (all optional in Phase 1): { seed?: int }
     * Item selection (Phase 4) and starting situation (Phase 7) plug in here later.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'seed' => ['nullable', 'integer'],
        ]);

        $run = $this->factory->create($data['seed'] ?? null);

        return response()->json($this->present($run), 201);
    }

    /**
     * GET /api/runs/{run} — full current state.
     */
    public function show(Run $run): JsonResponse
    {
        return response()->json($this->present($run));
    }

    /**
     * POST /api/runs/{run}/advance — end-of-day processing.
     *
     * Folded out as its own endpoint for Phase 1. The choice-resolution
     * endpoint (Phase 2) will drive advancement during normal play; this
     * direct endpoint stays useful for the simulation harness.
     */
    public function advance(Run $run): JsonResponse
    {
        $this->dayProcessor->advance($run);

        return response()->json($this->present($run));
    }

    /**
     * The wire shape of a run. Resource metadata (max/two_sided) is included
     * so the thin client can render bars and danger states without knowing
     * the tuning numbers itself.
     */
    private function present(Run $run): array
    {
        $meta = [];
        foreach (config('game.resources') as $code => $def) {
            $meta[$code] = [
                'max' => $def['max'],
                'two_sided' => $def['two_sided'],
            ];
        }

        return [
            'id' => $run->id,
            'day' => $run->day,
            'status' => $run->status,
            'seed' => $run->seed,
            'resources' => $run->resources,
            'resource_meta' => $meta,
        ];
    }
}
