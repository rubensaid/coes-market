"""Paquete JSON de una semana para el sitio PHP (cPanel) y subida al hosting.

El procesamiento pesado sigue en Python. El hosting solo recibe este resumen ya
calculado (indicadores, series agregadas, textos de la narrativa) y lo dibuja.
Los textos salen de la narrativa y del glosario de Python: no se duplican reglas en PHP.
"""

import gzip
import json
import math
from datetime import date, datetime, timedelta

import pandas as pd

from src.analysis import calcular_resumen, centrales, serie
from src.analysis.indicadores import coordenadas_barras
from src.narrative import CATEGORIA_HECHO, en_simple, explicar_hecho, generar_narrativa, niveles
from src.utils import config, estado
from src.utils.datos import leer_tabla
from src.utils.fechas import ahora_peru

ESQUEMA = 1
CENTRALES_CON_DETALLE = 30      # detalle completo de las N centrales con mas energia (mas las propias)
ESTADOS = {"confirmado": 0, "preliminar": 1, "en vivo": 2}


# ---------- conversion a JSON ----------

def _ts(valor):
    return pd.Timestamp(valor).strftime("%Y-%m-%d %H:%M")


def limpiar(obj):
    """Convierte a tipos JSON: NaN -> None, fechas -> texto, numpy -> python."""
    if obj is None:
        return None
    if isinstance(obj, (pd.Timestamp, datetime)):
        return None if pd.isna(obj) else _ts(obj)
    if isinstance(obj, date):
        return obj.isoformat()
    if isinstance(obj, dict):
        return {str(k): limpiar(v) for k, v in obj.items()}
    if isinstance(obj, (list, tuple)):
        return [limpiar(v) for v in obj]
    if isinstance(obj, pd.DataFrame):
        return limpiar(obj.to_dict("records"))
    if hasattr(obj, "item") and not isinstance(obj, (str, bytes)):
        obj = obj.item()
    if isinstance(obj, float):
        return None if (math.isnan(obj) or math.isinf(obj)) else round(obj, 4)
    return obj


def _col(df, nombre, dec=2):
    """Columna como lista redondeada (None donde falta)."""
    return [None if pd.isna(v) else round(float(v), dec) for v in df[nombre]]


# ---------- bloques ----------

def _indicadores(r):
    glosario = config.glosario()["indicadores"]
    salida = {}
    for clave, i in r.indicadores.items():
        salida[clave] = {
            "valor": i.valor, "unidad": i.unidad, "anterior": i.anterior, "anio_anterior": i.anio_anterior,
            "momento": i.momento, "es_puntos": i.es_puntos, "var_anterior": i.var_anterior,
            "var_anio": i.var_anio, "comparable": i.comparable,
            "niveles": niveles(r, clave) if clave in glosario else None,
        }
    return salida


def _hechos(r, narrativa):
    return [{"tipo": h.tipo, "clave": h.clave, "categoria": CATEGORIA_HECHO.get(h.clave, ""), "sentido": _sentido(h),
             "titular": titular, "explicacion": explicar_hecho(h, r)}
            for h, titular in zip(r.hechos, narrativa.titulares)]


def _sentido(h):
    """Direccion del hecho para el icono del sitio: sube/baja (variacion, anomalia) o max/min (record)."""
    if h.tipo == "variacion":
        return "sube" if h.datos["var"] > 0 else "baja"
    if h.tipo == "anomalia":
        return "sube" if h.datos["razon"] > 1 else "baja"
    return h.datos.get("sentido", "")


def _series_semana(r):
    dem, gen = r.serie_demanda, r.serie_generacion
    pivote = (gen.pivot_table(index="fecha_hora", columns="tecnologia", values="valor", aggfunc="sum").sort_index()
              if not gen.empty else pd.DataFrame())
    return {
        "perfil": [[int(f.hora), _r(f.actual), _r(f.anterior)] for f in r.perfil_cmg.itertuples()],
        "cmg": {"ts": [_ts(t) for t in r.serie_cmg["fecha_hora"]], "valor": _col(r.serie_cmg, "valor")},
        "demanda": {"ts": [_ts(t) for t in dem["fecha_hora"]], "ejecutada": _col(dem, "ejecutada", 1),
                    "prog_diaria": _col(dem, "prog_diaria", 1)} if not dem.empty else {},
        "generacion": {"ts": [_ts(t) for t in pivote.index],
                       "tecnologias": {t: _col(pivote, t, 1) for t in pivote.columns}} if not pivote.empty else {},
        "embalses": {"fecha": [d.isoformat() for d in r.serie_embalses["fecha"]],
                     "valor": _col(r.serie_embalses, "valor")} if not r.serie_embalses.empty else {},
        "embalses_detalle": limpiar(r.embalses_detalle) if not r.embalses_detalle.empty else [],
    }


def _r(v, dec=2):
    return None if v is None or pd.isna(v) else round(float(v), dec)


def _mapa(r):
    if r.cmg_barras.empty:
        return []
    return [{"barra": f.barra, "cmg_promedio": _r(f.cmg_promedio), "latitud": _r(f.latitud, 5),
             "longitud": _r(f.longitud, 5)} for f in r.cmg_barras.itertuples()]


def _eventos(r, cmg_ref, raiz=None):
    """Salidas de servicio, mantenimientos y eventos de red (vista Eventos)."""
    ev = centrales.eventos_semana(r.semana, raiz)
    if ev.empty:
        return {"hay": False}
    gen = ev[(ev["clase"] == "G") & (ev["mw"] > 0)]
    forzadas = gen[~gen["programado"]]
    dia = centrales.indisponible_por_dia(ev, r.desde, r.hasta)
    maximo = float((dia["programado"] + dia["forzado"]).max()) if len(dia) else 0.0
    grandes = []
    for f in gen.sort_values("mw", ascending=False).head(12).itertuples():
        grandes.append({"ubicacion": f.ubicacion, "equipo": f.equipo, "empresa": f.empresa, "inicio": f.inicio,
                        "final": f.final, "mw": _r(f.mw, 1), "programado": bool(f.programado),
                        "precio": _r(centrales.precio_durante(cmg_ref, f.inicio, f.final))})
    red = [{"inicio": f.inicio, "ubicacion": f.ubicacion, "equipo": f.equipo, "descripcion": str(f.descripcion)[:90]}
           for f in ev[ev["origen"] == "evento"].sort_values("inicio", ascending=False).head(8).itertuples()]
    return {
        "hay": True, "forzadas": int(len(forzadas)), "forzadas_mw": _r(forzadas["mw"].sum(), 0),
        "programadas": int(gen["programado"].sum()), "maximo_fuera_mw": _r(maximo, 0),
        "eventos_red": int((ev["origen"] == "evento").sum()),
        "por_dia": [{"fecha": f.fecha, "programado": _r(f.programado, 1), "forzado": _r(f.forzado, 1)}
                    for f in dia.itertuples()],
        "grandes": grandes, "red": red,
    }


def _centrales(r, raiz=None, ruta_db=None):
    """Empresas, ranking y detalle de las centrales principales (vista Centrales)."""
    cen = leer_tabla("centrales", r.semana, raiz)
    if cen.empty:
        return {"hay": False}
    emp = centrales.participacion_empresas(cen, leer_tabla("centrales", r.semana_anterior, raiz))
    top = centrales.top_centrales(cen, 10)
    nombres = centrales.lista_centrales(cen)
    principales = centrales.top_centrales(cen, CENTRALES_CON_DETALLE)["central"].tolist()
    propias = [c for c in config.interno().get("mis_centrales", []) if c in nombres]
    detalle = {}
    for central in dict.fromkeys(propias + principales):
        barra, aproximada = centrales.barra_de(central)
        cmg = serie.cmg_rango(r.desde, r.hasta, barra, raiz, ruta_db)
        d = centrales.detalle_central(cen, central, cmg)
        if not d:
            continue
        s = d.pop("serie")
        d.update({"barra": barra, "aproximada": aproximada, "ts": [_ts(t) for t in s["fecha_hora"]],
                  "serie": _col(s, "valor", 1)})
        detalle[central] = d
    por_defecto = propias[0] if propias else (top["central"].iloc[0] if len(top) else None)
    return {
        "hay": True, "lista": nombres, "por_defecto": por_defecto, "empresas": limpiar(emp),
        "top": limpiar(top), "detalle": limpiar(detalle),
    }


def _evolucion(r, raiz=None, ruta_db=None):
    """Serie del CMg de referencia con su estado, y de las demas barras del mapa (para la vista Evolucion)."""
    ref = serie.cmg_rango(r.desde, r.hasta, None, raiz, ruta_db)
    cmg = leer_tabla("costo_marginal", r.semana, raiz)
    otras = {}
    if not cmg.empty and not ref.empty:
        total = cmg[cmg["variable"] == "cmg_total"]
        ancho = total.pivot_table(index="fecha_hora", columns="entidad", values="valor").reindex(ref["fecha_hora"])
        permitidas = set(coordenadas_barras()["barra"]) | set(config.interno().get("barras_favoritas", []) or [])
        otras = {b: _col(ancho, b) for b in ancho.columns if b in permitidas and b != r.barra_referencia}
    return ref, {
        "ts": [_ts(t) for t in ref["fecha_hora"]], "total": _col(ref, "total"), "energia": _col(ref, "energia"),
        "congestion": _col(ref, "congestion"), "estado": [ESTADOS.get(e, 2) for e in ref["estado"]],
        "otras_barras": otras,
    }


def _tipo_cambio(r):
    from src.extract import bcrp
    try:
        tabla = bcrp.leer_cache()
    except Exception:
        return {}
    if tabla.empty:
        return {}
    dias = [r.desde + timedelta(days=i) for i in range((r.hasta - r.desde).days + 1)]
    tc = bcrp.tc_para(dias, tabla)
    return {d.isoformat(): _r(v, 4) for d, v in zip(dias, tc.values)}


def _fuentes():
    """Tabla de fuentes con su estado (vista Fuentes y pagina de metodo)."""
    nombres = {"demanda": "Demanda", "generacion": "Generación", "costo_marginal": "Precios (IEOD)",
               "ieod_anexo": "Embalses (IEOD)", "ahora_costo_marginal": "Precio en vivo",
               "ahora_demanda": "Demanda en vivo"}
    fuentes = config.fuentes()
    est = estado.leer()
    filas = [(k, f) for k, f in fuentes["fuentes"].items() if k in nombres]
    filas += [(f"ahora_{k}", f) for k, f in fuentes["ahora"].items() if isinstance(f, dict)]
    salida = []
    for clave, f in filas:
        ok = est.get(clave, {}).get("ultima_extraccion_exitosa")
        salida.append({"clave": clave, "nombre": nombres.get(clave, clave), "descripcion": f.get("descripcion", ""),
                       "frecuencia": f.get("frecuencia_objetivo", ""), "retraso": f.get("retraso_esperado", ""),
                       "estado_dato": "preliminar" if f.get("estado_dato") == "preliminar" else "confirmado",
                       "url_fuente": f.get("url_fuente", ""), "ultima_extraccion": ok})
    return salida


# ---------- paquete ----------

def paquete(semana, raiz=None, ruta_db=None) -> dict:
    """Todo lo que el sitio PHP necesita para mostrar una semana."""
    r = calcular_resumen(semana, raiz)
    narrativa = generar_narrativa(r)
    p = config.proyecto()
    ref, evolucion = _evolucion(r, raiz, ruta_db)
    datos = {
        "esquema": ESQUEMA,
        "proyecto": {"nombre": p["nombre"], "slug": p["slug"], "tagline": p["tagline"], "aviso": " ".join(p["aviso"].split()),
                     "version": p.get("version", "")},
        "generado_en": ahora_peru().replace(tzinfo=None),
        "semana": r.semana, "texto_semana": r.texto_semana, "desde": r.desde, "hasta": r.hasta,
        "semana_anterior": r.semana_anterior, "semana_anio_anterior": r.semana_anio_anterior,
        "es_preliminar": bool(r.es_preliminar), "dias_con_datos": r.dias_con_datos,
        "barra_referencia": r.barra_referencia, "umbrales_precio": r.umbrales_precio,
        "indicadores": _indicadores(r), "hechos": _hechos(r, narrativa),
        "narrativa": {"titulares": narrativa.titulares, "parrafos": narrativa.parrafos,
                      "titulos_graficos": narrativa.titulos_graficos},
        "en_simple": en_simple(r),
        "mix": r.mix, "mix_anterior": r.mix_anterior,
        "series": _series_semana(r), "mapa": _mapa(r),
        "rarezas": {"n": r.n_rarezas, "top": r.rarezas}, "revision": r.revision, "avisos": r.avisos,
        "eventos": _eventos(r, ref, raiz),
        "centrales": _centrales(r, raiz, ruta_db),
        "evolucion": evolucion, "tipo_cambio": _tipo_cambio(r),
        "fuentes": _fuentes(),
    }
    return limpiar(datos)


def a_bytes(datos) -> bytes:
    """JSON comprimido (gzip) listo para enviar."""
    return gzip.compress(json.dumps(datos, ensure_ascii=False, separators=(",", ":")).encode("utf-8"), 9)
