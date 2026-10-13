<?php
declare(strict_types=1);

namespace CuantoCoesta;

/**
 * Lector minimo de .xlsx en streaming (ZipArchive + XMLReader), sin PhpSpreadsheet para no agotar la memoria
 * del hosting compartido. Devuelve la primera hoja como filas[fila0][columna0] = valor (texto o numero).
 */
final class Xlsx
{
    /** @return array<int, array<int, string|float>> */
    public static function filas(string $contenido, ?string $hoja = null): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($tmp, $contenido);
        $zip = new \ZipArchive();
        try {
            if ($zip->open($tmp) !== true) {
                throw new \RuntimeException('El archivo no es un Excel valido.');
            }
            $compartidas = self::textosCompartidos($zip);
            $ruta = self::rutaHoja($zip, $hoja);
            $xml = $zip->getFromName($ruta);
            if ($xml === false) {
                throw new \RuntimeException("No se encontro la hoja $ruta.");
            }
            return self::leerHoja($xml, $compartidas);
        } finally {
            $zip->close();
            @unlink($tmp);
        }
    }

    public static function indiceColumna(string $referencia): int
    {
        preg_match('/^([A-Z]+)/', $referencia, $m);
        $n = 0;
        foreach (str_split($m[1] ?? 'A') as $letra) {
            $n = $n * 26 + (ord($letra) - 64);
        }
        return $n - 1;
    }

    private static function rutaHoja(\ZipArchive $zip, ?string $nombre): string
    {
        $libro = (string)$zip->getFromName('xl/workbook.xml');
        $rels = (string)$zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($libro === '' || $rels === '') {
            return 'xl/worksheets/sheet1.xml';
        }
        $wb = simplexml_load_string($libro);
        $wb->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $hojas = $wb->xpath('//m:sheets/m:sheet') ?: [];
        $elegida = null;
        foreach ($hojas as $h) {
            if ($nombre === null || (string)$h['name'] === $nombre) {
                $elegida = $h;
                break;
            }
        }
        if ($elegida === null) {
            throw new \RuntimeException("No existe la hoja $nombre.");
        }
        $rid = (string)$elegida->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        $r = simplexml_load_string($rels);
        foreach ($r->Relationship as $rel) {
            if ((string)$rel['Id'] === $rid) {
                $destino = (string)$rel['Target'];
                return ltrim(str_starts_with($destino, '/') ? $destino : "xl/$destino", '/');
            }
        }
        return 'xl/worksheets/sheet1.xml';
    }

    /** @return string[] */
    private static function textosCompartidos(\ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }
        $lector = new \XMLReader();
        $lector->XML($xml);
        $textos = [];
        while ($lector->read()) {
            if ($lector->nodeType === \XMLReader::ELEMENT && $lector->name === 'si') {
                $textos[] = self::textoDe($lector->readOuterXml());
            }
        }
        $lector->close();
        return $textos;
    }

    private static function textoDe(string $si): string
    {
        $nodo = simplexml_load_string($si);
        if ($nodo === false) {
            return '';
        }
        $texto = '';
        if (isset($nodo->t)) {
            $texto = (string)$nodo->t;
        }
        foreach ($nodo->r ?? [] as $run) {
            $texto .= (string)$run->t;   // texto con formato: se concatenan los tramos (no las notas fonéticas)
        }
        return $texto;
    }

    /** @return array<int, array<int, string|float>> */
    private static function leerHoja(string $xml, array $compartidas): array
    {
        $lector = new \XMLReader();
        $lector->XML($xml);
        $filas = [];
        $fila = -1;
        while ($lector->read()) {
            if ($lector->nodeType !== \XMLReader::ELEMENT) {
                continue;
            }
            if ($lector->name === 'row') {
                $fila = $lector->getAttribute('r') !== null ? (int)$lector->getAttribute('r') - 1 : $fila + 1;
            } elseif ($lector->name === 'c') {
                $ref = (string)$lector->getAttribute('r');
                $tipo = (string)$lector->getAttribute('t');
                $celda = simplexml_load_string($lector->readOuterXml());
                $valor = null;
                if ($tipo === 'inlineStr') {
                    $valor = (string)$celda->is->t;
                } elseif (isset($celda->v) && (string)$celda->v !== '') {
                    $v = (string)$celda->v;
                    $valor = match ($tipo) {
                        's' => $compartidas[(int)$v] ?? '',
                        'str', 'e' => $v,
                        'b' => $v === '1' ? '1' : '0',
                        default => is_numeric($v) ? (float)$v : $v,
                    };
                }
                if ($valor !== null && $fila >= 0) {
                    $filas[$fila][self::indiceColumna($ref)] = $valor;
                }
            }
        }
        $lector->close();
        return $filas;
    }
}
