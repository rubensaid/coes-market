<?php
declare(strict_types=1);

namespace CuantoCoesta;

/** Crea las tablas de sql/schema.sql (idempotente) y las carpetas de storage/. */
final class Instalador
{
    /** @return array<int, array{string, bool, string}> pasos: [descripcion, ok, detalle] */
    public static function ejecutar(): array
    {
        $pasos = [];
        foreach (['pdf', 'logs'] as $sub) {
            $ok = is_dir(Config::storage($sub)) && is_writable(Config::storage($sub));
            $pasos[] = ["Carpeta storage/$sub escribible", $ok, $ok ? '' : 'Dale permisos 750 o 755 desde el Administrador de archivos.'];
        }
        try {
            $sql = (string)file_get_contents(Config::raiz() . '/sql/schema.sql');
            $sql = preg_replace('/^\s*--.*$/m', '', $sql);
            $n = 0;
            foreach (array_filter(array_map('trim', explode(';', (string)$sql))) as $sentencia) {
                Db::pdo()->exec($sentencia);
                $n++;
            }
            $pasos[] = ["Tablas creadas o verificadas ($n sentencias)", true, ''];
            file_put_contents(Config::storage() . '/instalado.lock', Fechas::ahora()->format('c'));
        } catch (\Throwable $e) {
            $pasos[] = ['Base de datos', false, $e->getMessage()];
        }
        return $pasos;
    }
}
