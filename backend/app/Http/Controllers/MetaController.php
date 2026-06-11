<?php

namespace App\Http\Controllers;

use App\Game\ThemeConfig;
use App\Models\Profile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Meta progression: research points and unlocks that persist across runs.
 * Unlocks add content (per design §2.1), so this controller only moves points
 * and records owned unlocks — the *effect* of an unlock is enforced elsewhere
 * (RunFactory makes the granted item pickable).
 */
class MetaController extends Controller
{
    public function __construct(private readonly ThemeConfig $theme)
    {
    }
    /**
     * GET /api/meta?handle=... — the profile's points, owned unlocks, and the
     * catalogue of buyable unlocks (with affordability + owned flags).
     */
    public function show(Request $request): JsonResponse
    {
        $profile = Profile::resolve($request->query('handle', 'anonymous'));
        $theme = $request->query('theme', 'space');

        return response()->json($this->present($profile, $theme));
    }

    /**
     * POST /api/meta/unlock — spend research points to buy an unlock.
     * Body: { handle?: string, key: string }
     */
    public function unlock(Request $request): JsonResponse
    {
        $data = $request->validate([
            'handle' => ['nullable', 'string', 'max:120'],
            'key' => ['required', 'string'],
        ]);

        $theme = $request->query('theme', 'space');
        $profile = Profile::resolve($data['handle'] ?? 'anonymous');
        $unlock = collect($this->theme->for($theme)->get('unlocks'))->firstWhere('key', $data['key']);

        if ($unlock === null) {
            return response()->json(['error' => 'Sblocco sconosciuto.'], 422);
        }
        if ($profile->hasUnlock($unlock['key'])) {
            return response()->json(['error' => 'Già sbloccato.'], 422);
        }
        if ($profile->research_points < $unlock['cost']) {
            return response()->json(['error' => 'Punti ricerca insufficienti.'], 422);
        }

        $profile->research_points -= $unlock['cost'];
        $profile->unlocks = array_values(array_merge($profile->unlocks ?? [], [$unlock['key']]));
        $profile->save();

        return response()->json($this->present($profile, $theme));
    }

    private function present(Profile $profile, string $theme = 'space'): array
    {
        $owned = $profile->unlocks ?? [];

        return [
            'handle' => $profile->handle,
            'research_points' => $profile->research_points,
            'unlocks_owned' => $owned,
            'unlocks' => collect($this->theme->for($theme)->get('unlocks'))->map(fn ($u) => [
                'key' => $u['key'],
                'name' => $u['name'],
                'description' => $u['description'],
                'cost' => $u['cost'],
                'owned' => in_array($u['key'], $owned, true),
                'affordable' => $profile->research_points >= $u['cost'],
            ])->all(),
        ];
    }
}
