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
            $texto .= (string)$run->t;   // texto con formato: se concatenan los tramos (no las notas foneticas)
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

    /**
     * Escribe un .xlsx sencillo (texto y numeros) sin librerias. @param array<string, array<int, array<int, mixed>>> $hojas nombre => filas
     * @return string contenido del archivo
     */
    public static function escribir(array $hojas): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $ns = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
        $tipos = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
        $libro = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook ' . $ns . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $i = 0;
        foreach ($hojas as $nombre => $filas) {
            $i++;
            $tipos .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $libro .= '<sheet name="' . htmlspecialchars(mb_substr($nombre, 0, 31), ENT_XML1) . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>';
            $rels .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
            $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet ' . $ns . '><sheetData>';
            foreach ($filas as $r => $fila) {
                $xml .= '<row r="' . ($r + 1) . '">';
                foreach (array_values($fila) as $c => $v) {
                    $ref = self::letraColumna($c) . ($r + 1);
                    if ($v === null || $v === '') {
                        continue;
                    }
                    $xml .= is_int($v) || is_float($v)
                        ? '<c r="' . $ref . '"><v>' . (is_float($v) ? rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.') : $v) . '</v></c>'
                        : '<c r="' . $ref . '" t="inlineStr"><is><t>' . htmlspecialchars((string)$v, ENT_XML1) . '</t></is></c>';
                }
                $xml .= '</row>';
            }
            $zip->addFromString("xl/worksheets/sheet$i.xml", $xml . '</sheetData></worksheet>');
        }
        $zip->addFromString('[Content_Types].xml', $tipos . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', $libro . '</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', $rels . '</Relationships>');
        $zip->close();
        $contenido = (string)file_get_contents($tmp);
        @unlink($tmp);
        return $contenido;
    }

    private static function letraColumna(int $i): string
    {
        $s = '';
        for ($n = $i + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $s = chr(65 + ($n - 1) % 26) . $s;
        }
        return $s;
    }
}
