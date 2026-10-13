"""Exporta la configuracion y los recursos que el sitio PHP necesita (web/config y web/public/assets).

config/*.yaml sigue siendo la fuente unica: aqui solo se convierte a JSON para PHP.
"""

import csv
import json
import shutil
from pathlib import Path

from src.utils import config

RAIZ_WEB = config.RAIZ / "web"


def exportar_config(destino=None):
    """Escribe proyecto.json, glosario.json, analisis.json y ahora.json."""
    destino = Path(destino or RAIZ_WEB / "config")
    destino.mkdir(parents=True, exist_ok=True)
    f = config.fuentes()
    ahora = dict(f["ahora"])
    salida = {
        "proyecto": config.proyecto(),
        "glosario": config.glosario(),
        "analisis": config.cargar_yaml("analisis"),
        "ahora": {
            "portal": f["portal"], "cliente": {k: f["cliente"][k] for k in ("user_agent", "pausa_segundos",
                                                                              "timeout_segundos", "reintentos")},
            "barra_referencia": f["barra_referencia"], "ahora": ahora, "meses_ieod": f["meses_ieod"],
            "programa": {"ruta_visor": "Operación/Programa de Operación/Programa Diario/{anio}/{mes}_{MES}/Día {dia}/"
                                       "Anexo1_Despacho_{aaaammdd}.xlsx"},
        },
    }
    for nombre, contenido in salida.items():
        (destino / f"{nombre}.json").write_text(json.dumps(contenido, ensure_ascii=False, indent=1), encoding="utf-8")
    return sorted(salida)


def exportar_recursos(destino=None):
    """Copia fuentes, mapa del Peru y coordenadas de barras a web/public/assets."""
    destino = Path(destino or RAIZ_WEB / "assets")
    (destino / "fuentes").mkdir(parents=True, exist_ok=True)
    for ttf in (config.RAIZ / "assets" / "fuentes").glob("*"):
        shutil.copy(ttf, destino / "fuentes" / ttf.name)
    mapas = config.RAIZ / "assets" / "mapas"
    shutil.copy(mapas / "peru.geo.json", destino / "peru.geo.json")
    with open(mapas / "barras_coordenadas.csv", encoding="utf-8") as f:
        barras = {fila["barra"]: [float(fila["latitud"]), float(fila["longitud"])] for fila in csv.DictReader(f)}
    (destino / "barras.json").write_text(json.dumps(barras, ensure_ascii=False), encoding="utf-8")
    return len(barras)
