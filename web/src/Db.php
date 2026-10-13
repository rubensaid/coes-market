<?php
declare(strict_types=1);

namespace CuantoCoesta;

/** Conexion PDO a MySQL con la hora de Peru (UTC-5) fija en la sesion. */
final class Db
{
    private static ?\PDO $pdo = null;

    public static function pdo(): \PDO
    {
        if (self::$pdo === null) {
            $d = Config::local()['db'] ?? null;
            if (!$d || empty($d['nombre'])) {
                throw new \RuntimeException('Falta la seccion db en config/local.php.');
            }
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $d['host'] ?? 'localhost', $d['puerto'] ?? 3306, $d['nombre']);
            self::$pdo = new \PDO($dsn, $d['usuario'] ?? '', $d['clave'] ?? '', [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            self::$pdo->exec("SET time_zone = '-05:00'");
        }
        return self::$pdo;
    }

    public static function usar(?\PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    public static function filas(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public static function fila(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $f = $st->fetch();
        return $f === false ? null : $f;
    }

    public static function ejecutar(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }
}
