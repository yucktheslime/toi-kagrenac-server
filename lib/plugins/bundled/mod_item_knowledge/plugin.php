<?php
// Prompt injector: the operator's fact list, or the bundled examples, becomes reference data.
// This file only returns hook callables, so requesting it over HTTP prints nothing.

return [
    'prompt' => function (array $context): ?string {
        $facts = trim((string)($context['settings']['facts'] ?? ''));
        if ($facts === '') {
            return null;
        }
        $lines = [];
        foreach (preg_split('/\R/u', $facts) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        if ($lines === []) {
            return null;
        }
        return "Known modded items and facts. Use them only as background knowledge:\n- " . implode("\n- ", array_slice($lines, 0, 40));
    },
];
