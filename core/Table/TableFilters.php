<?php

declare(strict_types=1);

namespace CodeVault\Table;

/**
 * Shared wire-format + SQL helpers for the admin table columns feature:
 * click-to-sort headers (WHMCS-style A-Z / 1-0) plus the per-column filter
 * row (see the filter row under each admin table header).
 *
 * Filter state travels in the query string as `filters[key]=value`; sort
 * state as `sort=column&dir=asc|desc` — both whitelisted per page by the
 * repository that owns the SQL. Every layer uses the same helpers so a sort
 * or filter entered in one column stays active across pagination, status
 * tabs and the free-text search box:
 *
 *   - controllers read + sanitise `filters[]` via fromQuery() and the sort
 *     via sortFromQuery()
 *   - repositories build WHERE via where() and ORDER BY via orderBy()
 *   - views render the bound filter inputs, sortable headers, and the
 *     pagination links that preserve the active filters/sort via query()
 */
final class TableFilters
{
    /**
     * Read + sanitise `filters[]` from a query bag, keeping only whitelisted
     * keys whose value is non-empty after trimming.
     *
     * @param array<string, mixed> $query   e.g. $request->all()
     * @param array<string, mixed> $allowed whitelist (key => anything)
     * @return array<string, string>
     */
    public static function fromQuery(array $query, array $allowed): array
    {
        $raw = $query['filters'] ?? null;

        if (!is_array($raw)) {
            return [];
        }

        $out = [];

        foreach ($allowed as $key => $_) {
            $value = trim((string) ($raw[$key] ?? ''));

            if ($value !== '') {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /** @param array<string, string> $filters */
    public static function active(array $filters): bool
    {
        return $filters !== [];
    }

    /**
     * Build the WHERE fragment + bindings for a set of sanitised filters,
     * using a per-page column map owned by the calling repository.
     *
     * $columns shape: key => [0: SQL expr or array of exprs (OR'd), 1: type]
     *   type 'like'   → `expr LIKE '%value%'`   (text / partial match)
     *   type 'eq'     → `expr = ?`              (exact, e.g. status)
     *   type 'number' → `expr = ?` with only digits kept (IDs, amounts)
     *
     * Every expr is a literal whitelisted column from the repository — never
     * user input — and every value goes through a bound parameter.
     *
     * @param array<string, string> $filters
     * @param array<string, array{0: string|array<int, string>, 1: string}> $columns
     * @return array{0: string, 1: array<int, string|int>}
     */
    public static function where(array $filters, array $columns): array
    {
        $conditions = [];
        $bindings = [];

        foreach ($filters as $key => $value) {
            if (!isset($columns[$key])) {
                continue;
            }

            [$exprs, $type] = $columns[$key];
            $exprs = (array) $exprs;

            if ($exprs === []) {
                continue;
            }

            if ($type === 'number') {
                // Keep digits + the decimal point so "ORD-13" / "$19.99" /
                // "49.50" all resolve to a plain numeric comparison (the
                // minus sign is dropped: order ids and amounts are positive).
                $numeric = preg_replace('/[^0-9.]/', '', $value);

                if ($numeric === '') {
                    continue;
                }

                $perExpr = [];

                foreach ($exprs as $expr) {
                    $perExpr[] = "{$expr} = ?";
                    $bindings[] = (float) $numeric;
                }

                $conditions[] = '(' . implode(' OR ', $perExpr) . ')';

                continue;
            }

            $operator = $type === 'like' ? 'LIKE' : '=';
            $needle = $type === 'like' ? "%{$value}%" : $value;
            $perExpr = [];

            foreach ($exprs as $expr) {
                $perExpr[] = "{$expr} {$operator} ?";
                $bindings[] = $needle;
            }

            $conditions[] = '(' . implode(' OR ', $perExpr) . ')';
        }

        return [implode(' AND ', $conditions), $bindings];
    }

    /**
     * Read + whitelist the current sort from the query bag.
     *
     * `sort` names a column in $sortable; `dir` is 'asc' or 'desc' (default
     * 'asc'). Returns null when there's no sort or the column isn't sortable.
     *
     * @param array<string, mixed> $query
     * @param array<string, string> $sortable key => SQL ORDER BY expression
     * @return array{column: string, dir: string}|null
     */
    public static function sortFromQuery(array $query, array $sortable): ?array
    {
        $column = trim((string) ($query['sort'] ?? ''));

        if ($column === '' || !isset($sortable[$column])) {
            return null;
        }

        $dir = strtolower(trim((string) ($query['dir'] ?? 'asc')));

        return ['column' => $column, 'dir' => $dir === 'desc' ? 'desc' : 'asc'];
    }

    /**
     * Build the ORDER BY fragment for a whitelisted sort, or '' when there is
     * none / the column isn't sortable (the caller keeps its default order).
     *
     * @param array<string, string> $sortable key => SQL ORDER BY expression
     * @param array{column: string, dir: string}|null $sort
     */
    public static function orderBy(array $sortable, ?array $sort): string
    {
        if ($sort === null || !isset($sortable[$sort['column']])) {
            return '';
        }

        $dir = ($sort['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';

        return 'ORDER BY ' . $sortable[$sort['column']] . ' ' . $dir;
    }

    /**
     * Build a query-string fragment (leading '?') for links, preserving the
     * active filters, the current sort, and any extra params (status tabs,
     * search box…), with an optional page override.
     *
     * @param array<string, string> $filters
     * @param array<string, string> $extra
     * @param array{column: string, dir: string}|null $sort
     */
    public static function query(array $filters, array $extra = [], ?int $page = null, ?array $sort = null): string
    {
        $parts = [];

        foreach ($filters as $key => $value) {
            $parts[] = 'filters[' . rawurlencode($key) . ']=' . rawurlencode($value);
        }

        foreach ($extra as $key => $value) {
            if ($value !== '' && $value !== null) {
                $parts[] = rawurlencode($key) . '=' . rawurlencode((string) $value);
            }
        }

        if ($sort !== null) {
            $parts[] = 'sort=' . rawurlencode($sort['column']);
            $parts[] = 'dir=' . rawurlencode($sort['dir']);
        }

        if ($page !== null) {
            $parts[] = 'page=' . max(1, $page);
        }

        return $parts === [] ? '' : '?' . implode('&', $parts);
    }

    /**
     * Hidden inputs preserving filters, extra params and the sort, for GET
     * filter forms (so submitting a filter doesn't drop the active sort).
     *
     * @param array<string, string> $filters
     * @param array<string, string> $extra
     * @param array{column: string, dir: string}|null $sort
     */
    public static function hidden(array $filters, array $extra = [], ?array $sort = null): string
    {
        $out = '';

        foreach ($filters as $key => $value) {
            $out .= '<input type="hidden" name="filters[' . self::h($key) . ']" value="' . self::h($value) . '">';
        }

        foreach ($extra as $key => $value) {
            if ($value !== '' && $value !== null) {
                $out .= '<input type="hidden" name="' . self::h($key) . '" value="' . self::h((string) $value) . '">';
            }
        }

        if ($sort !== null) {
            $out .= '<input type="hidden" name="sort" value="' . self::h($sort['column']) . '">';
            $out .= '<input type="hidden" name="dir" value="' . self::h($sort['dir']) . '">';
        }

        return $out;
    }

    private static function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
