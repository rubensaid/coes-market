# Desplegar Cuánto COESta en un hosting compartido con cPanel (PHP y MySQL)

Esta guía te lleva de cero a un sitio funcionando, con el reporte semanal llegando a tu correo.
No necesitas SSH ni Python en el hosting: todo se hace desde cPanel y por FTP.

## Cómo funciona

```
 GitHub Actions (lunes 7:00 a. m. Lima) o tu PC              Tu hosting con cPanel
 ───────────────────────────────────────────                 ───────────────────────────────────
 run_weekly.py --pdf   (Python: descarga, limpia,   ──POST──▶ api/recibir.php  → MySQL (1 fila por semana)
 python -m src.publicar  calcula, narra, arma el PDF)                          → guarda el PDF
                                                                               → envía el PDF a tu correo
 Cada 30 minutos: cron de PHP ─▶ bin/ahora.php (consulta al COES) ─▶ MySQL (solo la barra de referencia)
 Visitantes ─▶ index.php: 12 secciones con gráficos SVG
```

- **Python hace el trabajo pesado** (descargas, limpieza, indicadores, textos y PDF) y sube solo un resumen comprimido.
  Por eso MySQL ocupa poco: unos 20 a 25 MB al año.
- **PHP muestra el sitio, consulta "Ahora" cada 30 minutos y envía el correo.**

## Qué necesitas

- Hosting con cPanel, PHP 8.1 o superior, MySQL o MariaDB, correo SMTP y Cron.
- Extensiones de PHP: `pdo_mysql`, `curl`, `mbstring`, `zlib`, `json`, `openssl`, `zip`, `xml` (en cPanel: *Seleccionar versión de PHP* > *Extensiones*).
- Una cuenta de GitHub con este repositorio (para el envío semanal automático) o una PC con Python 3.11 o superior.

## Paso 1. Prepara el paquete

En tu PC, dentro del proyecto:

```
python tools/empaquetar_web.py
```

Se crea `dist/cuanto-coesta-web.zip` (unos 600 KB, con PHPMailer incluido, sin secretos).

## Paso 2. Crea la base de datos

En cPanel, *Bases de datos MySQL*:

1. Crea una base (por ejemplo `coesta`). cPanel le agrega el prefijo de tu cuenta: `micuenta_coesta`.
2. Crea un usuario con una clave larga.
3. Agrega el usuario a la base con *Todos los privilegios*.

## Paso 3. Sube el sitio

1. En *Administrador de archivos* abre `public_html/` (o la carpeta de un subdominio) y sube el zip.
2. *Extract*. Queda la carpeta `coesta/`. Tu sitio será `https://tudominio.com/coesta/`.
3. Dentro de `coesta/config/` copia `local.ejemplo.php` como `local.php` y complétalo:
   - `db`: host (`localhost`), nombre, usuario y clave del paso 2.
   - `token`: una clave aleatoria de 32 caracteres o más (la usarán Python, el instalador y el cron por URL).
   - `smtp` y `correo`: ver el paso 5.
   - `url_sitio`: la dirección pública, sin barra final.

## Paso 4. Instala y revisa

1. Abre `https://tudominio.com/coesta/instalar.php` y escribe el `token`. Crea las tablas.
2. Abre `diagnostico.php`. Todo debe salir en verde: versión de PHP, extensiones, **hora de Perú (-05:00)**, MySQL,
   salida hacia coes.org.pe y servidor SMTP. Si algo sale en rojo, el texto dice cómo corregirlo.
3. Abre `clave.php`, escribe una clave para el panel y copia el resultado en `local.php`, en `'admin' => ['clave_hash' => ...]`.
4. **Borra del hosting** `instalar.php` y `clave.php` cuando termines.

## Paso 5. Configura el correo del reporte

1. En cPanel, *Cuentas de correo*, crea `reportes@tudominio.com`.
2. En *Connect Devices* de esa cuenta copia el servidor saliente (normalmente `mail.tudominio.com`) y el puerto (465 con SSL).
3. Completa en `local.php`:

```php
'smtp' => ['host' => 'mail.tudominio.com', 'puerto' => 465, 'seguridad' => 'ssl',
           'usuario' => 'reportes@tudominio.com', 'clave' => '...', 'remitente' => 'reportes@tudominio.com',
           'nombre_remitente' => 'Cuánto COESta'],
'correo' => ['destinatarios' => ['tu_correo@ejemplo.com']],
```

4. En `diagnostico.php` pulsa *Enviar correo de prueba*. Si cae en spam la primera vez, márcalo como "no es spam".

## Paso 6. Programa "Ahora" cada 30 minutos

En cPanel, *Trabajos de Cron*, crea uno con *Cada 30 minutos* (`*/30 * * * *`):

```
/usr/local/bin/php /home/TU_USUARIO/public_html/coesta/bin/ahora.php
```

(si tu hosting usa `ea-php81`: `/usr/local/bin/ea-php81 /home/TU_USUARIO/public_html/coesta/bin/ahora.php`).

Si tu plan solo permite cron con enlaces:

```
wget -q -O /dev/null "https://tudominio.com/coesta/cron.php?tarea=ahora&clave=TU_TOKEN"
```

Además, quien visita la sección *Ahora* puede pulsar *Actualizar ahora* (el servidor lo limita a una consulta cada 5 minutos).
Si el hosting estuvo sin cron, la primera consulta rellena hasta 7 días hacia atrás.

## Paso 7. Publica la primera semana

### Opción A: automática con GitHub (recomendada)

1. En GitHub: *Settings* > *Secrets and variables* > *Actions* > *New repository secret*:
   - `COESTA_HOSTING_URL` = `https://tudominio.com/coesta`
   - `COESTA_HOSTING_TOKEN` = el `token` de `local.php`
2. En *Actions* > *Reporte semanal* > *Run workflow*.
3. Desde ahí, cada lunes a las 7:00 a. m. (hora de Lima) se descarga, se calcula, se arma el PDF, se sube al hosting y te llega por correo.

### Opción B: desde tu PC

```
set COESTA_HOSTING_URL=https://tudominio.com/coesta
set COESTA_HOSTING_TOKEN=tu_token
.venv\Scripts\python run_weekly.py --pdf --publicar
```

En Linux o Mac usa `export` en vez de `set`. Para repetir solo la subida: `python -m src.publicar --semana 2026-W40`.
Con `--sin-correo` sube sin enviar el correo.

## Panel de administración

`https://tudominio.com/coesta/admin.php`: ver semanas publicadas, reenviar el PDF por correo, enviar un correo de prueba,
consultar "Ahora" a mano, borrar una semana y revisar las últimas consultas al COES y los últimos correos.

## Qué se ve en el sitio

Las mismas 12 secciones de la app de escritorio: Resumen, Ahora, Evolución (con exportación a Excel), Precios, Demanda,
Generación, Embalses, Eventos, Centrales, Mapa, Fuentes y Glosario, más "Sobre el proyecto". Funciona en celular.
Cada página lleva el aviso de que el proyecto es independiente y no está afiliado al COES.

## Qué cambia respecto a la app de escritorio

- El PDF no se genera en el hosting: llega ya hecho desde Python y se descarga desde *Resumen*.
- El botón *Actualizar* de Resumen (bajar una semana) no existe: bajar y calcular semanas es trabajo de Python.
- En *Evolución* se pueden elegir la barra de referencia y las barras del mapa (las que guardó la última semana publicada).
- En *Centrales* están las 30 centrales con más energía y las propias (`mis_centrales` de `config/interno.yaml`).
  Las barras propias de `interno.yaml` se aplican al calcular en Python.

## Mantenimiento y espacio

- Para conservar solo las últimas N semanas, pon `'conservar_semanas' => N` en `local.php`.
- Respaldo: exporta la base desde cPanel > *phpMyAdmin* y copia `storage/pdf/`.
- Para actualizar el sitio: vuelve a correr `python tools/empaquetar_web.py`, sube el zip y extrae sobre la carpeta
  (tu `config/local.php` y `storage/` no están en el zip, así que no se pisan).

## Problemas frecuentes

| Síntoma | Qué revisar |
|---|---|
| `diagnostico.php` marca salida a coes.org.pe en rojo | El hosting bloquea conexiones salientes. Pide a soporte habilitar `curl` hacia `www.coes.org.pe` por HTTPS. "Ahora" depende de eso; el resto del sitio funciona igual con lo que sube Python. |
| La subida devuelve 401 | El `token` de `local.php` y el secreto `COESTA_HOSTING_TOKEN` deben ser idénticos. |
| La subida devuelve 413 o "supera el límite" | Sube `upload_max_filesize` y `post_max_size` a 32M en *Seleccionar versión de PHP* > *Opciones*. |
| No llega el correo | Revisa *Últimos correos* en `admin.php` (guarda el motivo). Prueba el puerto 587 con `'seguridad' => 'tls'`. |
| Las horas salen corridas | `diagnostico.php` debe mostrar -05:00 en PHP y en MySQL. El sitio lo fuerza, pero avisa si no lo logra. |
| Un cron se corta | Tu plan limita la duración. El cron de "Ahora" tarda unos 10 a 15 segundos por la pausa de 2 s entre pedidos. |

## Seguridad

- `config/local.php` guarda secretos: no lo subas a git (ya está ignorado) y usa claves largas.
- Las carpetas `src/`, `config/`, `storage/`, `bin/`, `sql/` y `vendor/` traen un `.htaccess` que bloquea el acceso web.
  Si tu hosting ignora `.htaccess`, mueve esas carpetas fuera de `public_html/` y ajusta la ruta en `src/autoload.php`.
- Nada de lo que muestra el sitio es privado: son datos públicos del COES.
