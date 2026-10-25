<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Service;

use SilverStripe\ORM\Connect\MySQLDatabase;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;

/**
 * Runs domain operations atomically on the primary database connection
 * (read replicas are never used inside an operation).
 */
final class Transaction
{
    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function run(callable $callback): mixed
    {
        return DB::withPrimary(static function () use ($callback): mixed {
            $result = null;
            DB::get_conn(DB::CONN_PRIMARY)->withTransaction(static function () use ($callback, &$result): void {
                $result = $callback();
            });

            return $result;
        });
    }

    /**
     * Pessimistic row lock (SELECT ... FOR UPDATE) on the base table of $class.
     * Prevents two dispatchers from moving the same visit / tour concurrently.
     * No-op on databases without row locking support (e.g. SQLite in tests).
     *
     * @param class-string<DataObject> $class
     * @param int[] $ids
     */
    public static function lock(string $class, array $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return;
        }
        $conn = DB::get_conn(DB::CONN_PRIMARY);
        if (!$conn instanceof MySQLDatabase && !str_contains($conn::class, 'Postgre')) {
            return;
        }
        $table = DataObject::getSchema()->baseDataTable($class);
        DB::prepared_query(
            sprintf('SELECT "ID" FROM "%s" WHERE "ID" IN (%s) FOR UPDATE', $table, DB::placeholders($ids)),
            $ids
        );
    }
}
