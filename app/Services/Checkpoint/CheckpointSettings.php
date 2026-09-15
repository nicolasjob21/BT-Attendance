<?php

namespace App\Services\Checkpoint;

use App\Models\Setting;

/** Module settings with config-file fallbacks. */
class CheckpointSettings
{
    public const KEY_DEFAULTS = 'checkpoints.defaults';

    public const KEY_INSTRUCTIONS = 'checkpoints.instructions';

    /** @return array{response_window_minutes:int} */
    public function defaults(): array
    {
        $saved = (array) Setting::get(self::KEY_DEFAULTS, []);

        return array_map('intval', array_merge(config('checkpoints.defaults'), array_filter($saved, fn ($v) => $v !== null && $v !== '')));
    }

    /** @return list<string> */
    public function instructions(): array
    {
        $saved = Setting::get(self::KEY_INSTRUCTIONS);
        $list = is_array($saved) && $saved !== [] ? $saved : config('checkpoints.instructions', []);

        return array_values(array_unique(array_filter(array_map(fn ($s) => trim((string) $s), $list))));
    }

    public function save(array $defaults, array $instructions): void
    {
        Setting::set(self::KEY_DEFAULTS, array_map('intval', $defaults));
        Setting::set(self::KEY_INSTRUCTIONS, array_values(array_unique(array_filter(array_map('trim', $instructions)))));
    }
}
