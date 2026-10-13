/* Cuanto COESta: hover de graficos SVG, globos de ayuda y actualizacion de "Ahora". Sin librerias. */
(function () {
  "use strict";
  var globo = null;

  function crearGlobo() {
    if (!globo) { globo = document.createElement("div"); globo.id = "globo"; globo.setAttribute("role", "tooltip"); document.body.appendChild(globo); }
    return globo;
  }
  function mostrarGlobo(el) {
    var g = crearGlobo(); g.textContent = el.getAttribute("data-tip"); g.style.display = "block";
    var r = el.getBoundingClientRect(), ancho = g.offsetWidth;
    g.style.left = Math.max(8, Math.min(window.innerWidth - ancho - 8, r.left + r.width / 2 - ancho / 2)) + "px";
    g.style.top = (r.bottom + 8) + "px";
  }
  function ocultarGlobo() { if (globo) { globo.style.display = "none"; } }

  document.addEventListener("mouseover", function (e) { var b = e.target.closest && e.target.closest("button.btn-info"); if (b) { mostrarGlobo(b); } });
  document.addEventListener("mouseout", function (e) { if (e.target.closest && e.target.closest("button.btn-info")) { ocultarGlobo(); } });
  document.addEventListener("focusin", function (e) { if (e.target.matches && e.target.matches("button.btn-info")) { mostrarGlobo(e.target); } });
  document.addEventListener("focusout", ocultarGlobo);
  document.addEventListener("click", function (e) {
    var b = e.target.closest && e.target.closest("button.btn-info");
    if (b) { if (globo && globo.style.display === "block") { ocultarGlobo(); } else { mostrarGlobo(b); } } else { ocultarGlobo(); }
  });

  /* ---------- hover de graficos ---------- */
  function iniciarGrafico(svg) {
    var datos; try { datos = JSON.parse(svg.getAttribute("data-hover") || "[]"); } catch (e) { datos = []; }
    var modo = svg.getAttribute("data-modo") || "x";
    var calor = null;
    if (modo === "calor") { try { calor = { x0: +svg.dataset.x0, y0: +svg.dataset.y0, cw: +svg.dataset.cw, ch: +svg.dataset.ch,
        filas: JSON.parse(svg.dataset.filas), fechas: JSON.parse(svg.dataset.fechas), unidad: svg.dataset.unidad || "S/" }; } catch (e) { return; } }
    if (!datos.length && !calor) { return; }
    var caja = document.createElement("div"); caja.className = "grafico-caja"; svg.parentNode.insertBefore(caja, svg); caja.appendChild(svg);
    var tip = document.createElement("div"); tip.className = "tip"; caja.appendChild(tip);
    var punto = document.createElement("div"); punto.className = "cursor"; caja.appendChild(punto);
    var vb = svg.viewBox.baseVal, radio = +svg.dataset.radio || 14;

    function aSvg(ev) {
      var r = svg.getBoundingClientRect(), t = ev.touches ? ev.touches[0] : ev;
      return { x: (t.clientX - r.left) / r.width * vb.width, y: (t.clientY - r.top) / r.height * vb.height, r: r };
    }
    function poner(px, py, texto, r, sinPunto) {
      var ex = px / vb.width * r.width, ey = py / vb.height * r.height;
      tip.textContent = texto; tip.classList.add("on");
      var ancho = tip.offsetWidth, izq = ex + 14;
      if (izq + ancho > r.width) { izq = ex - ancho - 14; }
      tip.style.left = Math.max(0, izq) + "px"; tip.style.top = Math.max(0, ey - tip.offsetHeight - 10) + "px";
      if (sinPunto) { punto.classList.remove("on"); } else { punto.style.left = ex + "px"; punto.style.top = ey + "px"; punto.classList.add("on"); }
    }
    function fuera() { tip.classList.remove("on"); punto.classList.remove("on"); }
    function mover(ev) {
      var p = aSvg(ev), i, mejor = -1, d, dm = Infinity;
      if (calor) {
        var col = Math.floor((p.x - calor.x0) / calor.cw), fila = Math.floor((p.y - calor.y0) / calor.ch);
        if (fila < 0 || fila >= calor.filas.length || col < 0 || col > 47 || calor.filas[fila][col] === null) { return fuera(); }
        var h = Math.floor(col / 2), m = (col % 2) * 30;
        var v = calor.filas[fila][col].toLocaleString("en-US");
        return poner(p.x, p.y, calor.fechas[fila] + " " + h + ":" + (m < 10 ? "0" : "") + m + " h\n" + calor.unidad + " " + v + " por MWh", p.r, true);
      }
      for (i = 0; i < datos.length; i++) {
        d = modo === "x" ? Math.abs(datos[i][0] - p.x) : modo === "y" ? Math.abs(datos[i][1] - p.y)
          : Math.pow(datos[i][0] - p.x, 2) + Math.pow(datos[i][1] - p.y, 2);
        if (d < dm) { dm = d; mejor = i; }
      }
      if (mejor < 0 || (modo === "cerca" && dm > radio * radio)) { return fuera(); }
      poner(datos[mejor][0], datos[mejor][1], datos[mejor][2], p.r, false);
    }
    svg.addEventListener("mousemove", mover); svg.addEventListener("touchstart", mover, { passive: true });
    svg.addEventListener("touchmove", mover, { passive: true }); svg.addEventListener("mouseleave", fuera);
    svg.addEventListener("touchend", function () { setTimeout(fuera, 1500); });
  }
  document.querySelectorAll("svg.grafico").forEach(iniciarGrafico);

  /* ---------- Actualizar ahora ---------- */
  function actualizarAhora(boton) {
    var url = (document.body.getAttribute("data-base") || "") + "api/actualizar.php";
    if (boton) { boton.disabled = true; boton.textContent = "Consultando al COES..."; }
    return fetch(url, { method: "POST", credentials: "same-origin" }).then(function (r) { return r.json(); }).then(function (j) {
      if (boton) { boton.textContent = j && j.omitido ? j.detalle : "Listo."; }
      setTimeout(function () { location.reload(); }, j && j.omitido ? 2200 : 400);
    }).catch(function () { if (boton) { boton.disabled = false; boton.textContent = "No se pudo consultar. Reintenta."; } });
  }
  document.querySelectorAll("[data-actualizar-ahora]").forEach(function (b) { b.addEventListener("click", function () { actualizarAhora(b); }); });
  var auto = document.querySelector("[data-auto-ahora]");
  if (auto) {
    var minutos = +auto.getAttribute("data-auto-ahora") || 30;
    /* Al abrir: si el dato tiene mas de 40 minutos, se consulta al COES (el servidor limita a una vez cada 5 min) */
    if (auto.getAttribute("data-viejo") === "1" && !sessionStorageSeguro("ahora-consultado")) {
      sessionStorageSeguro("ahora-consultado", "1"); actualizarAhora(null);
    }
    var casilla = document.getElementById("auto-30");
    setInterval(function () { if (!casilla || casilla.checked) { location.reload(); } }, minutos * 60 * 1000);
  }
  function sessionStorageSeguro(clave, valor) {
    try { if (valor === undefined) { return sessionStorage.getItem(clave); } sessionStorage.setItem(clave, valor); } catch (e) { return null; }
    return null;
  }
  document.querySelectorAll("select[data-auto]").forEach(function (s) { s.addEventListener("change", function () { s.form.submit(); }); });
})();
