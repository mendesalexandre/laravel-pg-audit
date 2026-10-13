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

    /** Caminho de migration com timestamp único (avança 1s se o arquivo já existir). */
    protected function migrationPath(string $name): string
    {
        $time = time();
        do {
            $path = database_path('migrations/'.date('Y_m_d_His', $time++).'_'.$name.'.php');
        } while (file_exists($path));

        return $path;
    }
}
