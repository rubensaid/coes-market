"""Arma el zip del sitio PHP listo para subir por FTP o por el Administrador de archivos de cPanel.

Uso: python tools/empaquetar_web.py [--salida dist/cuanto-coesta-web.zip]

Antes de empaquetar vuelve a exportar config/*.yaml y los recursos a web/ para que el zip no quede viejo.
Nunca incluye config/local.php (secretos), pruebas, ni lo guardado en storage/.
"""

import argparse
import sys
import zipfile
from pathlib import Path

RAIZ = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(RAIZ))

from src.publicar import web  # noqa: E402

EXCLUIR_DIRS = {"tests", "__pycache__", ".git"}
EXCLUIR_ARCHIVOS = {"local.php", ".gitignore"}


def archivos_del_sitio(base: Path):
    """Archivos de web/ que se suben al hosting."""
    for ruta in sorted(base.rglob("*")):
        rel = ruta.relative_to(base)
        if ruta.is_dir() or EXCLUIR_DIRS & set(rel.parts) or ruta.name in EXCLUIR_ARCHIVOS:
            continue
        if rel.parts[0] == "storage" and ruta.name not in {".htaccess", ".gitkeep"}:
            continue   # PDF y logs de otra instalacion no viajan
        yield ruta, rel


def empaquetar(salida: Path) -> int:
    web.exportar_config()
    web.exportar_recursos()
    base = RAIZ / "web"
    salida.parent.mkdir(parents=True, exist_ok=True)
    n = 0
    with zipfile.ZipFile(salida, "w", zipfile.ZIP_DEFLATED) as z:
        for ruta, rel in archivos_del_sitio(base):
            z.write(ruta, str(Path("coesta") / rel))
            n += 1
    return n


def main(argv=None):
    parser = argparse.ArgumentParser(description="Empaqueta web/ para subirlo al hosting")
    parser.add_argument("--salida", default=str(RAIZ / "dist" / "cuanto-coesta-web.zip"))
    args = parser.parse_args(argv)
    salida = Path(args.salida)
    n = empaquetar(salida)
    print(f"{n} archivos en {salida} ({salida.stat().st_size / 1024:.0f} KB)")
    print("Descomprime en public_html/ (queda la carpeta coesta/), crea config/local.php desde local.ejemplo.php y abre instalar.php.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
