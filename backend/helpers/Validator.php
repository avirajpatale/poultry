<?php

declare(strict_types=1);

/**
 * Minimal request validator.
 * $rules: ['field' => 'required|numeric|min:0|in:a,b|date|int|max:100']
 * Throws ApiException(422) with the first error found.
 */
class Validator
{
    public static function validate(array $data, array $rules): array
    {
        $clean = [];
        foreach ($rules as $field => $ruleStr) {
            $rulesArr = explode('|', $ruleStr);
            $value    = $data[$field] ?? null;
            $present  = !($value === null || $value === '');

            if (in_array('required', $rulesArr, true) && !$present) {
                throw new ApiException("Field '{$field}' is required", 422);
            }
            if (!$present) {
                $clean[$field] = null;
                continue;
            }

            // min:/max: mean numeric bounds when the field is declared numeric/int,
            // otherwise they're string-length limits (so phone "9800000001" passes max:20).
            $isNumeric = in_array('numeric', $rulesArr, true) || in_array('int', $rulesArr, true);

            foreach ($rulesArr as $rule) {
                if ($rule === 'required') continue;
                if ($rule === 'numeric') {
                    if (!is_numeric($value)) throw new ApiException("'{$field}' must be numeric", 422);
                    $value = $value + 0;
                } elseif ($rule === 'int') {
                    if (filter_var($value, FILTER_VALIDATE_INT) === false && !(is_int($value))) {
                        throw new ApiException("'{$field}' must be an integer", 422);
                    }
                    $value = (int)$value;
                } elseif ($rule === 'date') {
                    $d = DateTime::createFromFormat('Y-m-d', (string)$value);
                    if (!$d || $d->format('Y-m-d') !== $value) {
                        throw new ApiException("'{$field}' must be a date (YYYY-MM-DD)", 422);
                    }
                } elseif ($rule === 'email') {
                    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        throw new ApiException("'{$field}' must be a valid email", 422);
                    }
                } elseif ($rule === 'array') {
                    if (!is_array($value)) throw new ApiException("'{$field}' must be a list", 422);
                } elseif (str_starts_with($rule, 'min:')) {
                    $min = (float)substr($rule, 4);
                    if ($isNumeric ? $value < $min : strlen((string)$value) < $min) {
                        throw new ApiException($isNumeric ? "'{$field}' must be ≥ {$min}" : "'{$field}' too short", 422);
                    }
                } elseif (str_starts_with($rule, 'max:')) {
                    $max = (float)substr($rule, 4);
                    if ($isNumeric ? $value > $max : strlen((string)$value) > $max) {
                        throw new ApiException($isNumeric ? "'{$field}' must be ≤ {$max}" : "'{$field}' too long", 422);
                    }
                } elseif (str_starts_with($rule, 'in:')) {
                    $opts = explode(',', substr($rule, 3));
                    if (!in_array($value, $opts, true)) {
                        throw new ApiException("'{$field}' must be one of: " . implode(', ', $opts), 422);
                    }
                }
            }
            $clean[$field] = $value;
        }
        return $clean;
    }

    /** Pagination params (page, per_page capped at 200). */
    public static function pageParams(array $q): array
    {
        $page    = max(1, (int)($q['page'] ?? 1));
        $perPage = min(200, max(1, (int)($q['per_page'] ?? 20)));
        return [$page, $perPage, ($page - 1) * $perPage];
    }
}
