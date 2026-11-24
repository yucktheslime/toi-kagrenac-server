<?php
// Sample custom action and a quiet game_event hook. This file only returns hook callables.

return [
    'action' => function (array $context): array {
        return [
            [
                'name' => 'wave',
                'when' => 'only when you agree to wave, greet someone or say hello with a wave',
                'arg' => 'optional',
                'arg_hint' => 'who you are waving at',
            ],
        ];
    },
    'game_event' => function (array $event): void {
        $type = is_string($event['type'] ?? null) ? $event['type'] : '';
        if ($type === '') {
            return;
        }
        // Type only, never the event text (that is untrusted game input).
        error_log('example-ai plugin sample_wave: game_event ' . $type);
    },
];
