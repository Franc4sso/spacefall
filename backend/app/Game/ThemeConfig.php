<?php

namespace App\Game;

use InvalidArgumentException;

/**
 * Resolves theme-scoped configuration. Replaces direct config('game.X') reads
 * so the engine can serve multiple themes (space, island) from the same code.
 * Each theme's data lives in config/themes/{theme}.php.
 */
final class ThemeConfig
{
    private const THEMES = ['space', 'island'];

    private ?string $theme = null;

    /**
     * Bind to a theme. Returns a fresh instance so callers never share state.
     */
    public function for(string $theme): self
    {
        if (! in_array($theme, self::THEMES, true)) {
            throw new InvalidArgumentException("Unknown theme: {$theme}");
        }
        $clone = new self();
        $clone->theme = $theme;
        return $clone;
    }

    /**
     * Read a dotted key within the bound theme, e.g. get('resources').
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if ($this->theme === null) {
            throw new InvalidArgumentException('Call for($theme) before get().');
        }
        return config("themes.{$this->theme}.{$key}", $default);
    }

    /** @return list<string> */
    public static function all(): array
    {
        return self::THEMES;
    }
}
