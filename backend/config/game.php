<?php

// Back-compat shim: legacy config('game.X') resolves to the space theme.
// New code MUST go through App\Game\ThemeConfig instead. This shim keeps the
// existing tests green until every call-site is migrated; the final task
// of this plan removes it.
return require __DIR__.'/themes/space.php';
