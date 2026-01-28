<?php
/**
 * Query Builder Helper
 *
 * Helps construct Listmonk subscriber queries from conditions
 */

class QueryBuilder
{
    /**
     * Known attributes with their value types
     * Note: Drip state (status, active, stage) is now in SQLite, not Listmonk attributes
     * Use list membership queries for product targeting instead
     */
    public const ATTRIBUTES = [
        'status' => ['type' => 'enum', 'values' => ['enabled', 'blocklisted'], 'column' => true],
        'country' => ['type' => 'text', 'values' => []],
        'freemius_user_id' => ['type' => 'text', 'values' => []],
    ];

    /**
     * Available operators
     */
    public const OPERATORS = [
        '=' => 'equals',
        '!=' => 'not equals',
        'LIKE' => 'contains',
        'IS NULL' => 'is empty',
        'IS NOT NULL' => 'is not empty',
    ];

    /**
     * Get all known attributes
     */
    public static function getAttributes(): array
    {
        return self::ATTRIBUTES;
    }

    /**
     * Get all operators
     */
    public static function getOperators(): array
    {
        return self::OPERATORS;
    }

    /**
     * Build a query string from conditions
     *
     * @param array $conditions Array of conditions with keys: attribute, operator, value, conjunction
     * @return string SQL-like query for Listmonk
     */
    public static function buildQuery(array $conditions): string
    {
        if (empty($conditions)) {
            return '';
        }

        $parts = [];

        foreach ($conditions as $index => $condition) {
            $attribute = $condition['attribute'] ?? '';
            $operator = $condition['operator'] ?? '=';
            $value = $condition['value'] ?? '';
            $conjunction = $condition['conjunction'] ?? 'AND';

            if (empty($attribute)) {
                continue;
            }

            // Build the condition
            $part = self::buildCondition($attribute, $operator, $value);

            if ($index > 0 && !empty($part)) {
                $part = strtoupper($conjunction) . ' ' . $part;
            }

            if (!empty($part)) {
                $parts[] = $part;
            }
        }

        return implode(' ', $parts);
    }

    /**
     * Build a single condition
     */
    private static function buildCondition(string $attribute, string $operator, string $value): string
    {
        // Check if this is a direct column (like status) or a JSON attribute
        $attrConfig = self::ATTRIBUTES[$attribute] ?? [];
        $isColumn = $attrConfig['column'] ?? false;

        $field = $isColumn
            ? "subscribers.$attribute"
            : "subscribers.attribs->>'$attribute'";

        switch ($operator) {
            case '=':
            case '!=':
                $escapedValue = self::escapeValue($value);
                return "$field $operator '$escapedValue'";

            case 'LIKE':
                $escapedValue = self::escapeValue($value);
                return "$field LIKE '%$escapedValue%'";

            case 'IS NULL':
                return "($field IS NULL OR $field = '')";

            case 'IS NOT NULL':
                return "($field IS NOT NULL AND $field != '')";

            default:
                return '';
        }
    }

    /**
     * Escape a value for use in a query
     */
    private static function escapeValue(string $value): string
    {
        // Basic SQL injection prevention
        return str_replace(["'", "\\"], ["''", "\\\\"], $value);
    }

    /**
     * Parse a raw query string into conditions (best effort)
     */
    public static function parseQuery(string $query): array
    {
        // This is a simplified parser - complex queries may not parse correctly
        $conditions = [];
        $query = trim($query);

        if (empty($query)) {
            return $conditions;
        }

        // Split by AND/OR (keeping the conjunction)
        $pattern = '/\s+(AND|OR)\s+/i';
        $parts = preg_split($pattern, $query, -1, PREG_SPLIT_DELIM_CAPTURE);

        $conjunction = 'AND';
        foreach ($parts as $part) {
            $part = trim($part);

            if (strtoupper($part) === 'AND' || strtoupper($part) === 'OR') {
                $conjunction = strtoupper($part);
                continue;
            }

            $condition = self::parseCondition($part);
            if ($condition) {
                $condition['conjunction'] = $conjunction;
                $conditions[] = $condition;
                $conjunction = 'AND'; // Reset for next iteration
            }
        }

        return $conditions;
    }

    /**
     * Parse a single condition string
     */
    private static function parseCondition(string $conditionStr): ?array
    {
        // Try to match: attribs->>'attribute' operator 'value'
        $patterns = [
            // IS NULL / IS NOT NULL
            "/subscribers\.attribs->>'([^']+)'\s+IS\s+(NOT\s+)?NULL/i" => function ($matches) {
                return [
                    'attribute' => $matches[1],
                    'operator' => isset($matches[2]) ? 'IS NOT NULL' : 'IS NULL',
                    'value' => ''
                ];
            },
            // = or != with value
            "/subscribers\.attribs->>'([^']+)'\s*(=|!=)\s*'([^']*)'/i" => function ($matches) {
                return [
                    'attribute' => $matches[1],
                    'operator' => $matches[2],
                    'value' => $matches[3]
                ];
            },
            // LIKE
            "/subscribers\.attribs->>'([^']+)'\s+LIKE\s+'%([^%]*)%'/i" => function ($matches) {
                return [
                    'attribute' => $matches[1],
                    'operator' => 'LIKE',
                    'value' => $matches[2]
                ];
            },
        ];

        foreach ($patterns as $pattern => $handler) {
            if (preg_match($pattern, $conditionStr, $matches)) {
                return $handler($matches);
            }
        }

        return null;
    }

    /**
     * Validate a raw query syntax (basic validation)
     */
    public static function validateQuery(string $query): array
    {
        $errors = [];

        // Check for dangerous patterns
        $dangerous = ['/;/', '/--/', '/\/\*/', '/DROP/i', '/DELETE/i', '/UPDATE/i', '/INSERT/i'];
        foreach ($dangerous as $pattern) {
            if (preg_match($pattern, $query)) {
                $errors[] = 'Query contains potentially dangerous SQL';
                break;
            }
        }

        // Check balanced parentheses
        $open = substr_count($query, '(');
        $close = substr_count($query, ')');
        if ($open !== $close) {
            $errors[] = 'Unbalanced parentheses';
        }

        // Check balanced quotes
        $quotes = substr_count($query, "'");
        if ($quotes % 2 !== 0) {
            $errors[] = 'Unbalanced quotes';
        }

        return $errors;
    }
}
