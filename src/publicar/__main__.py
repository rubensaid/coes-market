"""CLI: python -m src.publicar --semana 2026-W40 [--pdf] [--sin-correo] [--solo-archivo salida.json.gz]

  --config-web     solo exporta config/*.yaml y los recursos a web/ (sin publicar nada)
"""

import argparse
import sys
from pathlib import Path

from src.publicar import a_bytes, paquete
from src.publicar import subir as hosting
from src.publicar import web
from src.utils import config
from src.utils.fechas import ultima_semana_completa
from src.utils.logs import configurar_logging, obtener_logger

log = obtener_logger("publicar")


def publicar(semana, con_pdf=True, enviar_correo=True, solo_archivo=None):
    """Arma el paquete de la semana y lo sube (o lo guarda en un archivo). Devuelve la respuesta del hosting."""
    datos = paquete(semana)
    cuerpo = a_bytes(datos)
    log.info("Paquete %s: %.0f KB comprimido", semana, len(cuerpo) / 1024)
    if solo_archivo:
        Path(solo_archivo).write_bytes(cuerpo)
        return {"ok": True, "archivo": str(solo_archivo)}
    ruta_pdf = config.DIR_REPORTS / f"{config.proyecto()['slug']}_{semana}.pdf"
    if con_pdf and not ruta_pdf.exists():
        log.warning("No existe %s: se publica sin PDF (corre run_weekly.py --pdf)", ruta_pdf)
        ruta_pdf = None
    return hosting.subir(semana, cuerpo, ruta_pdf if con_pdf else None, enviar_correo)


def main(argv=None):
    parser = argparse.ArgumentParser(description="Publica la semana en el sitio PHP del hosting")
    parser.add_argument("--semana", default=None, help="AAAA-Www (por defecto la ultima completa)")
    parser.add_argument("--sin-pdf", action="store_true", help="no adjuntar el PDF")
    parser.add_argument("--sin-correo", action="store_true", help="subir sin enviar el correo")
    parser.add_argument("--solo-archivo", default=None, help="guarda el paquete .json.gz en vez de subirlo")
    parser.add_argument("--config-web", action="store_true", help="exporta config y recursos a web/")
    args = parser.parse_args(argv)
    configurar_logging()
    if args.config_web:
        print("Config exportada:", ", ".join(web.exportar_config()), "|", web.exportar_recursos(), "barras con coordenadas")
        return 0
    semana = args.semana or ultima_semana_completa()
    try:
        respuesta = publicar(semana, not args.sin_pdf, not args.sin_correo, args.solo_archivo)
    except Exception:
        log.exception("No se pudo publicar %s", semana)
        return 1
    print(f"Publicado {semana}: {respuesta}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
