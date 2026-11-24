<?php

namespace App\Connector;

/**
 * Converts submitted HTML form values into typed JSON arguments matching a discovered MCP tool's inputSchema.
 */
final readonly class ToolArgumentCoercer
{
    /**
     * @param array<int|string, mixed>                                                                         $rawArgs
     * @param list<array{name: string, type: string, description: string, required: bool, enum: list<string>}> $fields
     *
     * @return array<string, mixed>
     */
    public function coerce(array $rawArgs, array $fields): array
    {
        $byName = [];
        foreach ($fields as $field) {
            $byName[$field['name']] = $field['type'];
        }

        $result = [];
        foreach ($rawArgs as $name => $value) {
            if (!\is_string($name) || '' === $name) {
                continue;
            }
            $str = is_scalar($value) ? trim((string) $value) : '';
            $type = $byName[$name] ?? 'string';

            if ('boolean' === $type) {
                if ('true' === $str || '1' === $str) {
                    $result[$name] = true;
                } elseif ('false' === $str || '0' === $str) {
                    $result[$name] = false;
                }
                continue;
            }
            if ('' === $str) {
                continue;
            }
            if ('integer' === $type && is_numeric($str)) {
                $result[$name] = (int) $str;
            } elseif ('number' === $type && is_numeric($str)) {
                $result[$name] = (float) $str;
            } elseif (\in_array($type, ['array', 'object'], true)) {
                $decoded = json_decode($str, true);
                $result[$name] = \JSON_ERROR_NONE === json_last_error() ? $decoded : $str;
            } else {
                $result[$name] = $str;
            }
        }

        return $result;
    }
}
