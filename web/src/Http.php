<?php
declare(strict_types=1);

namespace CuantoCoesta;

/** Cliente HTTP del portal del COES: User-Agent propio, pausa minima entre pedidos y reintentos (como src/extract/cliente.py). */
class Http
{
    private float $ultimo = 0.0;
    private array $cfg;

    public function __construct(?array $cfg = null)
    {
        $this->cfg = $cfg ?? Config::ahora()['cliente'];
    }

    public function portal(): string
    {
        return rtrim(Config::ahora()['portal'], '/');
    }

    private function esperarTurno(): void
    {
        $falta = (float)$this->cfg['pausa_segundos'] - (microtime(true) - $this->ultimo);
        if ($falta > 0) {
            usleep((int)($falta * 1e6));
        }
        $this->ultimo = microtime(true);
    }

    /** Pide una ruta del portal (GET con params en la URL, POST con formulario). Lanza RuntimeException si fallan los reintentos. */
    public function pedir(string $metodo, string $ruta, array $params = []): string
    {
        $url = str_starts_with($ruta, 'http') ? $ruta : $this->portal() . $ruta;
        return $this->pedirUrl($metodo, $url, $params);
    }

    public function pedirUrl(string $metodo, string $url, array $params = [], array $cabeceras = []): string
    {
        $ultimoError = '';
        $intentos = (int)$this->cfg['reintentos'];
        for ($i = 0; $i < $intentos; $i++) {
            $this->esperarTurno();
            $ch = curl_init();
            $destino = $url;
            $opciones = [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => (int)$this->cfg['timeout_segundos'], CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_USERAGENT => $this->cfg['user_agent'], CURLOPT_ENCODING => '',
                CURLOPT_HTTPHEADER => array_merge(['Accept: */*'], $cabeceras),
            ];
            if (strtoupper($metodo) === 'POST') {
                $opciones[CURLOPT_POST] = true;
                $opciones[CURLOPT_POSTFIELDS] = http_build_query($params);
            } elseif ($params) {
                $destino .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
            }
            $opciones[CURLOPT_URL] = $destino;
            curl_setopt_array($ch, $opciones);
            $cuerpo = curl_exec($ch);
            $codigo = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $ultimoError = curl_error($ch);
            curl_close($ch);
            if ($cuerpo !== false && $codigo === 200) {
                return (string)$cuerpo;
            }
            $ultimoError = $ultimoError ?: "HTTP $codigo";
            if ($i < $intentos - 1) {
                sleep(2 * (2 ** $i));
            }
        }
        throw new \RuntimeException("No se pudo consultar $url: $ultimoError");
    }

    /**
     * Archivo del visor de documentos del COES. Devuelve null si aun no esta publicado (archivo vacio).
     */
    public function archivoVisor(string $rutaVisor): ?string
    {
        $url = $this->portal() . '/browser/download?url=' . str_replace('%2F', '/', rawurlencode($rutaVisor));
        $cuerpo = $this->pedirUrl('GET', $url);
        return $cuerpo === '' ? null : $cuerpo;
    }
}
