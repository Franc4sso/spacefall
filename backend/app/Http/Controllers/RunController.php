<?php

namespace App\Http\Controllers;

use App\Game\DayProcessor;
use App\Game\Engine\EventEngine;
use App\Game\RunFactory;
use App\Models\Run;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RunController extends Controller
{
    public function __construct(
        private readonly RunFactory $factory,
        private readonly DayProcessor $dayProcessor,
        private readonly EventEngine $engine,
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
            'items' => ['nullable', 'array'],
            'items.*' => ['string'],
        ]);

        // The factory sanitises the pick (unknown keys dropped, capped at 5).
        $run = $this->factory->create($data['seed'] ?? null, $data['items'] ?? []);

        return response()->json($this->present($run), 201);
    }

    /**
     * GET /api/items — the full item catalogue for the start-of-run pick
     * screen, plus how many the player chooses.
     */
    public function items(): JsonResponse
    {
        return response()->json([
            'pick' => (int) config('game.items_pick'),
            'items' => config('game.items'),
        ]);
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
     * GET /api/runs/{run}/card — the card the player currently faces. Picks and
     * pins one if none is pinned. Always returns a card (filler guarantee).
     */
    public function card(Run $run): JsonResponse
    {
        return response()->json($this->present($run));
    }

    /**
     * POST /api/runs/{run}/choices — resolve the pinned card's choice.
     * Body: { choice: int } (index into the card's choices).
     * Returns the resolution log + the new run state (with the next card).
     */
    public function resolveChoice(Run $run, Request $request): JsonResponse
    {
        $data = $request->validate([
            'choice' => ['required', 'integer', 'min:0'],
        ]);

        $result = $this->engine->resolveChoice($run, $data['choice']);

        return response()->json([
            'resolution' => $result,
            'state' => $this->present($run->fresh()),
        ]);
    }

    /**
     * The wire shape of a run. Resource metadata (max/two_sided) is included
     * so the thin client can render bars and danger states without knowing
     * the tuning numbers itself. Always carries the current card so the client
     * can render in one round-trip (flow: §1.5).
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

        $payload = [
            'id' => $run->id,
            'day' => $run->day,
            'status' => $run->status,
            'seed' => $run->seed,
            'resources' => $run->resources,
            'resource_meta' => $meta,
            // Living survivors for the roster panel (Phase 9). Internal fields
            // (stress_band tracking) are not surfaced.
            'characters' => collect($run->characters ?? [])
                ->map(fn ($c) => [
                    'name' => $c['name'] ?? '?',
                    'role' => $c['role'] ?? null,
                    'traits' => $c['traits'] ?? [],
                    'stress' => $c['stress'] ?? 0,
                    'alive' => $c['alive'] ?? true,
                ])->all(),
            // Inventory as full item objects (key + Italian name/description)
            // so the client can render it without re-fetching the catalogue.
            'items' => $this->itemObjects($run->items ?? []),
            // Station systems (efficiency per system) for the status panel.
            'systems' => $run->systems ?? [],
            'card' => null,
        ];

        if ($run->status === 'active') {
            $card = $this->engine->currentCard($run);
            if ($card['event'] !== null) {
                $payload['card'] = [
                    'key' => $card['event']->key,
                    'title' => $card['event']->title,
                    'body' => $card['event']->body,
                    'speaker' => $card['event']->speaker,
                    'choices' => $card['choices'],
                ];
            }
        }

        return $payload;
    }

    /**
     * Expand stored item keys into full catalogue objects, skipping any key not
     * in the catalogue (defensive; the factory already sanitises).
     *
     * @param  list<string>  $keys
     * @return list<array<string,mixed>>
     */
    private function itemObjects(array $keys): array
    {
        $catalogue = collect(config('game.items'))->keyBy('key');

        return collect($keys)
            ->map(fn ($k) => $catalogue->get($k))
            ->filter()
            ->values()
            ->all();
    }
}
