<?php

namespace MendesAlexandre\PgAudit\Console\Concerns;

trait ExportsValues
{
    /** ['schema' => 'x', 'table' => null] => "schema: 'x'" (ignora nulos/vazios). */
    protected function exportArgs(array $args): string
    {
        $parts = [];
        foreach (array_filter($args, fn ($v) => $v !== null && $v !== '' && $v !== []) as $name => $value) {
            $parts[] = $name.': '.var_export($value, true);
        }

        return implode(', ', $parts);
    }
}
