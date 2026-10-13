"""Paquete JSON para el sitio PHP: contenido, tipos JSON validos y compresion."""

import gzip
import json

import pytest

from src.publicar import ESQUEMA, a_bytes, paquete
from tests.conftest import SEMANA_MUESTRA


@pytest.fixture(scope="module")
def datos(processed_muestra, tmp_path_factory):
    raiz, _, _ = processed_muestra
    return paquete(SEMANA_MUESTRA, raiz, tmp_path_factory.mktemp("ahora") / "ahora.sqlite")


def test_estructura_basica(datos):
    assert datos["esquema"] == ESQUEMA
    assert datos["semana"] == SEMANA_MUESTRA
    assert datos["es_preliminar"] is True
    for clave in ("indicadores", "hechos", "narrativa", "series", "mapa", "eventos", "centrales", "evolucion", "fuentes"):
        assert clave in datos


def test_proyecto_viene_de_config(datos):
    assert datos["proyecto"]["nombre"] and datos["proyecto"]["aviso"]
    assert "no afiliado" in datos["proyecto"]["aviso"].lower()


def test_textos_de_la_narrativa(datos):
    assert len(datos["hechos"]) == 3
    assert all(h["titular"] and h["explicacion"] for h in datos["hechos"])
    assert set(datos["narrativa"]["parrafos"]) == {"precios", "demanda", "generacion", "hidrologia"}
    assert "precios_serie" in datos["narrativa"]["titulos_graficos"]
    assert datos["indicadores"]["cmg_promedio"]["niveles"]["rapida"]


def test_series_alineadas(datos):
    ev = datos["evolucion"]
    assert len(ev["ts"]) == len(ev["total"]) == len(ev["estado"])
    assert all(len(v) == len(ev["ts"]) for v in ev["otras_barras"].values())
    g = datos["series"]["generacion"]
    assert all(len(v) == len(g["ts"]) for v in g["tecnologias"].values())


def test_json_valido_sin_nan_y_sin_em_dash(datos):
    texto = json.dumps(datos, ensure_ascii=False, allow_nan=False)
    assert "—" not in texto


def test_compresion_ida_y_vuelta(datos):
    crudo = gzip.decompress(a_bytes(datos)).decode("utf-8")
    assert json.loads(crudo)["semana"] == SEMANA_MUESTRA


# ---------- subida al hosting (sin red) ----------

class _Resp:
    def __init__(self, codigo, cuerpo=None):
        self.status_code, self._cuerpo, self.text = codigo, cuerpo or {}, str(cuerpo)

    def json(self):
        return self._cuerpo


class _Sesion:
    def __init__(self, *respuestas):
        self.respuestas, self.llamadas = list(respuestas), []

    def post(self, url, **kw):
        self.llamadas.append((url, kw))
        return self.respuestas.pop(0)


def test_subida_envia_token_y_archivos(tmp_path):
    from src.publicar import subir
    pdf = tmp_path / "x.pdf"
    pdf.write_bytes(b"%PDF-1.4")
    sesion = _Sesion(_Resp(200, {"ok": True}))
    r = subir.subir("2026-W40", b"gz", pdf, True, "https://ejemplo.com/coesta", "secreto", sesion)
    url, kw = sesion.llamadas[0]
    assert r == {"ok": True} and url == "https://ejemplo.com/coesta/api/recibir.php"
    assert kw["headers"]["Authorization"] == "Bearer secreto" and set(kw["files"]) == {"paquete", "pdf"}


def test_subida_rechazada_no_reintenta():
    from src.publicar import subir
    sesion = _Sesion(_Resp(401, "no"))
    with pytest.raises(subir.ErrorSubida):
        subir.subir("2026-W40", b"gz", None, False, "https://ejemplo.com", "malo", sesion)
    assert len(sesion.llamadas) == 1


def test_subida_exige_https_y_credenciales(monkeypatch):
    from src.publicar import subir
    monkeypatch.delenv("COESTA_HOSTING_URL", raising=False)
    monkeypatch.delenv("COESTA_HOSTING_TOKEN", raising=False)
    with pytest.raises(subir.ErrorSubida):
        subir.credenciales()
    with pytest.raises(subir.ErrorSubida):
        subir.credenciales("http://ejemplo.com", "t")


def test_exportar_config_web(tmp_path):
    from src.publicar import web
    assert "glosario" in web.exportar_config(tmp_path)
    assert json.loads((tmp_path / "proyecto.json").read_text(encoding="utf-8"))["slug"]
