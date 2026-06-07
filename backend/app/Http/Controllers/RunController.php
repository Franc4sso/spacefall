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
            'handle' => ['nullable', 'string', 'max:120'],
        ]);

        $profile = \App\Models\Profile::resolve($data['handle'] ?? 'anonymous');

        // The factory sanitises the pick (unknown/locked-unowned dropped, capped).
        $run = $this->factory->create($data['seed'] ?? null, $data['items'] ?? [], $profile);

        return response()->json($this->present($run), 201);
    }

    /**
     * GET /api/items — the item catalogue for the start-of-run pick screen,
     * filtered to what this profile may actually pick (locked items appear only
     * once their unlock is owned).
     */
    public function items(Request $request): JsonResponse
    {
        $profile = \App\Models\Profile::resolve($request->query('handle', 'anonymous'));
        $available = $this->factory->availableItemKeys($profile);

        $items = collect(config('game.items'))
            ->filter(fn ($i) => in_array($i['key'], $available, true))
            ->values()
            ->all();

        return response()->json([
            'pick' => (int) config('game.items_pick'),
            'items' => $items,
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
     * POST /api/runs/{run}/advance — consume the current card and end the day.
     *
     * Used by the client for silent cards (no choices), which auto-advance.
     * The pinned card is cleared first: a silent card never goes through choice
     * resolution, so without unpinning it here the same card would be drawn
     * again after the day advances and the UI would freeze on it. Also used by
     * the simulation harness to step a day directly.
     */
    public function advance(Run $run): JsonResponse
    {
        // Record the consumed card on cooldown (the same way choice resolution
        // does) so a silent card cannot be drawn again on the very next turn,
        // then unpin it before advancing the day.
        $consumed = $run->current_event_key;
        if ($consumed !== null) {
            $recent = $run->recent_events ?? [];
            $recent[$consumed] = $run->day;
            $run->recent_events = $recent;
        }
        $run->current_event_key = null;
        $run->save();

        $this->dayProcessor->advance($run->fresh());

        return response()->json($this->present($run->fresh()));
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
     * POST /api/runs/{run}/choices — resolve the pinned card's choice, then end
     * the day. Body: { choice: int } (index into the card's choices).
     *
     * One card = one turn = one day, matching the canonical loop the simulator
     * runs (currentCard -> resolveChoice -> advance). Resolving a choice clears
     * the pin; advancing the day then draws the next card for the new day. A
     * choice that ends the run (death/win) short-circuits: DayProcessor::advance
     * no-ops on an ended run, so the day does not tick past the ending.
     * Returns the resolution log + the new run state (with the next card).
     */
    public function resolveChoice(Run $run, Request $request): JsonResponse
    {
        $data = $request->validate([
            'choice' => ['required', 'integer', 'min:0'],
        ]);

        $result = $this->engine->resolveChoice($run, $data['choice']);

        // End the day and draw the next card (no-op if the choice ended the run).
        $this->dayProcessor->advance($run->fresh());

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
                    'hunger' => $c['hunger'] ?? 0,
                    'alive' => $c['alive'] ?? true,
                    'standing' => (int) ($run->flags['standing_' . strtolower((string) ($c['name'] ?? ''))] ?? 0),
                ])->all(),
            // Inventory as full item objects (key + Italian name/description)
            // so the client can render it without re-fetching the catalogue.
            'items' => $this->itemObjects($run->items ?? []),
            // Station systems (efficiency per system) for the status panel.
            'systems' => $run->systems ?? [],
            // Last 15 choices logged during the run.
            'choice_log' => array_slice($run->choice_log ?? [], -15),
            // Crew trust level (0-100+, default 60).
            'crew_trust' => (int) ($run->flags['crew_trust'] ?? 60),
            // Character epithet (il_freddo, il_leader, etc.) from profile.
            'epithet' => $run->profile?->flags['epithet'] ?? null,
            // The reached ending (null while active): key/type/name/text for
            // the game-over screen.
            'ending' => $this->endingPayload($run),
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
    /**
     * The run's reached ending as a display object, or null while active.
     * Read-only: reads the stored ending_key (the EndingService already
     * decided and persisted it).
     *
     * @return array<string,mixed>|null
     */
    private function endingPayload(Run $run): ?array
    {
        if ($run->ending_key === null) {
            return null;
        }
        $ending = collect(config('game.endings'))->firstWhere('key', $run->ending_key);
        if ($ending === null) {
            return null;
        }
        return [
            'key' => $ending['key'],
            'type' => $ending['type'],
            'name' => $ending['name'],
            'text' => $ending['text'],
            'epithet' => $run->profile?->flags['epithet'] ?? null,
        ];
    }

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
