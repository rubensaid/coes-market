"""Subida del paquete semanal (y del PDF) al sitio PHP del hosting.

Variables de entorno (nunca se guardan en el repositorio):
  COESTA_HOSTING_URL    base del sitio, por ejemplo https://tudominio.com/coesta
  COESTA_HOSTING_TOKEN  clave larga que tambien esta en web/config/local.php
"""

import os
import time
from pathlib import Path

import requests

from src.utils.logs import obtener_logger

log = obtener_logger(__name__)

REINTENTOS = 3
TIMEOUT = 120


class ErrorSubida(RuntimeError):
    """El hosting rechazo la subida o no respondio."""


def credenciales(url=None, token=None):
    """URL y token desde argumentos o entorno. Falla con un mensaje claro si faltan."""
    url = (url or os.environ.get("COESTA_HOSTING_URL", "")).rstrip("/")
    token = token or os.environ.get("COESTA_HOSTING_TOKEN", "")
    if not url or not token:
        raise ErrorSubida("Faltan COESTA_HOSTING_URL y COESTA_HOSTING_TOKEN (ver DESPLIEGUE_CPANEL.md).")
    if not url.startswith("https://") and "localhost" not in url and "127.0.0.1" not in url:
        raise ErrorSubida("La URL del hosting debe usar https:// para no enviar el token sin cifrar.")
    return url, token


def subir(semana, paquete_gz: bytes, ruta_pdf=None, enviar_correo=True, url=None, token=None, sesion=None):
    """Envia el paquete (y el PDF si se da) a api/recibir.php. Devuelve el JSON de respuesta."""
    url, token = credenciales(url, token)
    sesion = sesion or requests.Session()
    ultimo_error = None
    for intento in range(REINTENTOS):
        archivos = {"paquete": (f"{semana}.json.gz", paquete_gz, "application/gzip")}
        if ruta_pdf:
            archivos["pdf"] = (Path(ruta_pdf).name, Path(ruta_pdf).read_bytes(), "application/pdf")
        try:
            resp = sesion.post(f"{url}/api/recibir.php", files=archivos, timeout=TIMEOUT,
                               data={"semana": semana, "enviar_correo": "1" if enviar_correo else "0"},
                               headers={"Authorization": f"Bearer {token}"})
            if resp.status_code == 200:
                return resp.json()
            if resp.status_code in (400, 401, 403, 413):
                raise ErrorSubida(f"El hosting rechazo la subida ({resp.status_code}): {resp.text[:200]}")
            ultimo_error = f"HTTP {resp.status_code}: {resp.text[:200]}"
        except ErrorSubida:
            raise
        except (requests.RequestException, ValueError) as error:
            ultimo_error = str(error)
        log.warning("Subida fallida (intento %s de %s): %s", intento + 1, REINTENTOS, ultimo_error)
        time.sleep(2 * 2 ** intento)
    raise ErrorSubida(f"No se pudo subir {semana}: {ultimo_error}")
