<?php
declare(strict_types=1);
namespace App;

use PDO;

final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', env('DB_HOST'), env('DB_NAME'));
            self::$pdo = new PDO($dsn, env('DB_USER'), env('DB_PASS'), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            // MySQL runs in UTC; align NOW()/CURDATE() with PHP (America/Sao_Paulo) so "today" and "late" agree.
            self::$pdo->exec("SET time_zone = '" . date('P') . "'");
            // Hostinger's MariaDB defaults the connection to utf8mb4_general_ci while the tables are utf8mb4_unicode_ci:
            // comparing a bound value with a literal then fails with "Illegal mix of collations". Pin it.
            self::$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
        }
        return self::$pdo;
    }

    /** Parameterized query. Never interpolate input into $sql. */
    public static function q(string $sql, array $params = []): \PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st;
    }
}
