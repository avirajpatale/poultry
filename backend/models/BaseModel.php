<?php

declare(strict_types=1);

/** Thin PDO CRUD helper shared by controllers. */
abstract class BaseModel
{
    protected static string $table;
    protected static array  $fillable = [];

    protected static function db(): PDO
    {
        return Database::pdo();
    }

    public static function find(int $id): ?array
    {
        $st = self::db()->prepare('SELECT * FROM ' . static::$table . ' WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public static function findOrFail(int $id): array
    {
        $row = self::find($id);
        if (!$row) throw new ApiException(static::$table . ' record not found', 404);
        return $row;
    }

    /** Generic filtered + paginated list. $wheres is a list of "col op ?" fragments. */
    public static function paginate(array $wheres, array $params, array $q, string $orderBy = 'id DESC'): array
    {
        [$page, $perPage, $offset] = Validator::pageParams($q);
        $where = $wheres ? 'WHERE ' . implode(' AND ', $wheres) : '';
        $pdo   = self::db();

        $count = $pdo->prepare('SELECT COUNT(*) FROM ' . static::$table . " {$where}");
        $count->execute($params);
        $total = (int)$count->fetchColumn();

        $st = $pdo->prepare('SELECT * FROM ' . static::$table . " {$where}
                             ORDER BY {$orderBy} LIMIT {$perPage} OFFSET {$offset}");
        $st->execute($params);

        return [
            'items'    => $st->fetchAll(),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int)ceil($total / $perPage),
        ];
    }

    /** Insert using only fillable keys present in $data; returns new id. */
    public static function insert(array $data): int
    {
        $data['created_by'] = $data['created_by'] ?? Auth::id();
        $cols = array_values(array_intersect(array_keys($data), static::$fillable));
        $marks = implode(',', array_fill(0, count($cols), '?'));
        $sql = 'INSERT INTO ' . static::$table . ' (' . implode(',', $cols) . ") VALUES ({$marks})";
        $st  = self::db()->prepare($sql);
        $st->execute(array_map(fn($c) => $data[$c], $cols));
        return (int)self::db()->lastInsertId();
    }

    public static function updateById(int $id, array $data): void
    {
        $cols = array_values(array_intersect(array_keys($data), static::$fillable));
        if (!$cols) return;
        $set = implode(',', array_map(fn($c) => "{$c} = ?", $cols));
        $st  = self::db()->prepare('UPDATE ' . static::$table . " SET {$set} WHERE id = ?");
        $st->execute([...array_map(fn($c) => $data[$c], $cols), $id]);
    }
}
