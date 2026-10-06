/* ZiWood — comportamenti del sito (nessuna libreria esterna) */
(function () {
  "use strict";

  var ridotto = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  /* ---------- header: compatto dopo lo scroll ---------- */
  var header = document.querySelector(".header");
  function aggiornaHeader() {
    header.classList.toggle("fisso", window.scrollY > 40);
  }
  if (header) {
    aggiornaHeader();
    window.addEventListener("scroll", aggiornaHeader, { passive: true });
  }

  /* ---------- menu a schermo intero ---------- */
  var burger = document.querySelector(".burger");
  var menu = document.querySelector(".menu");
  function chiudiMenu() {
    document.body.classList.remove("menu-aperto");
    if (burger) burger.setAttribute("aria-expanded", "false");
  }
  if (burger && menu) {
    menu.querySelectorAll("li a").forEach(function (a, i) {
      a.style.setProperty("--d", (0.15 + i * 0.07) + "s");
    });
    burger.addEventListener("click", function () {
      var aperto = document.body.classList.toggle("menu-aperto");
      burger.setAttribute("aria-expanded", aperto ? "true" : "false");
    });
    menu.querySelectorAll("a").forEach(function (a) { a.addEventListener("click", chiudiMenu); });
    document.addEventListener("keydown", function (e) { if (e.key === "Escape") chiudiMenu(); });
  }

  /* ---------- hero: slideshow con dissolvenza ---------- */
  var slides = document.querySelectorAll(".hero-slide");
  if (slides.length > 1) {
    var punti = document.querySelector(".hero-punti");
    var corrente = 0, timer;
    if (punti) {
      slides.forEach(function (_, i) {
        var b = document.createElement("button");
        b.setAttribute("aria-label", "Foto " + (i + 1));
        b.addEventListener("click", function () { vai(i); riparti(); });
        punti.appendChild(b);
      });
    }
    function vai(i) {
      slides[corrente].classList.remove("attiva");
      if (punti) punti.children[corrente].classList.remove("attivo");
      corrente = (i + slides.length) % slides.length;
      slides[corrente].classList.add("attiva");
      if (punti) punti.children[corrente].classList.add("attivo");
    }
    function riparti() {
      clearInterval(timer);
      if (!ridotto) timer = setInterval(function () { vai(corrente + 1); }, 6500);
    }
    vai(0); riparti();
  }

  /* ---------- apparizioni allo scroll ---------- */
  var osservati = document.querySelectorAll(".reveal, .reveal-foto, .tappa");
  if ("IntersectionObserver" in window && !ridotto) {
    var io = new IntersectionObserver(function (voci) {
      voci.forEach(function (v) {
        if (v.isIntersecting) { v.target.classList.add("in"); io.unobserve(v.target); }
      });
    }, { threshold: 0.12, rootMargin: "0px 0px -8% 0px" });
    osservati.forEach(function (el) { io.observe(el); });
  } else {
    osservati.forEach(function (el) { el.classList.add("in"); });
  }

  /* ---------- contatori ---------- */
  var cifre = document.querySelectorAll("[data-conta]");
  if (cifre.length) {
    var ioC = new IntersectionObserver(function (voci) {
      voci.forEach(function (v) {
        if (!v.isIntersecting) return;
        ioC.unobserve(v.target);
        var el = v.target, fine = parseInt(el.getAttribute("data-conta"), 10), inizio = null;
        if (ridotto) { el.textContent = fine; return; }
        function passo(t) {
          if (!inizio) inizio = t;
          var p = Math.min((t - inizio) / 1800, 1);
          var e = 1 - Math.pow(1 - p, 3);
          el.textContent = p >= 1 ? fine : Math.round(fine * e);
          if (p < 1) requestAnimationFrame(passo);
        }
        requestAnimationFrame(passo);
        setTimeout(function () { el.textContent = fine; }, 2000); // rete di sicurezza
      });
    }, { threshold: 0.5 });
    cifre.forEach(function (el) { el.textContent = "0"; ioC.observe(el); });
  }

  /* ---------- leggero parallasse nell'hero ---------- */
  var hero = document.querySelector(".hero .container");
  if (hero && !ridotto) {
    window.addEventListener("scroll", function () {
      var y = window.scrollY;
      if (y < window.innerHeight) {
        hero.style.transform = "translateY(" + y * 0.25 + "px)";
        hero.style.opacity = 1 - y / (window.innerHeight * 0.9);
      }
    }, { passive: true });
  }

  /* ---------- galleria: filtri ---------- */
  var filtri = document.querySelectorAll(".filtri button");
  var lavori = document.querySelectorAll(".galleria .lavoro");
  filtri.forEach(function (b) {
    b.addEventListener("click", function () {
      filtri.forEach(function (x) { x.classList.remove("attivo"); });
      b.classList.add("attivo");
      var f = b.getAttribute("data-filtro");
      lavori.forEach(function (l, i) {
        var mostra = f === "tutti" || l.getAttribute("data-cat") === f;
        l.classList.toggle("nascosto", !mostra);
        if (mostra) {
          l.classList.remove("in");
          l.style.setProperty("--d", (i % 9) * 0.05 + "s");
          requestAnimationFrame(function () { requestAnimationFrame(function () { l.classList.add("in"); }); });
        }
      });
    });
  });

  /* ---------- lightbox ---------- */
  var lb = document.querySelector(".lightbox");
  if (lb && lavori.length) {
    /* ogni riquadro è un progetto: data-foto elenca tutte le sue foto, si sfogliano nel lightbox */
    var lbImg = lb.querySelector("img"), lbConta = lb.querySelector(".conta"), lbTitolo = lb.querySelector(".titolo");
    var foto = [], idx = 0, nome = "";
    function mostra(i) {
      idx = (i + foto.length) % foto.length;
      lbImg.style.opacity = 0;
      var pre = new Image();
      pre.onload = function () { lbImg.src = pre.src; lbImg.alt = nome; lbImg.style.opacity = 1; };
      pre.src = foto[idx];
      if (lbConta) lbConta.textContent = (idx + 1) + " / " + foto.length;
      if (lbTitolo) lbTitolo.textContent = nome;
    }
    lavori.forEach(function (l) {
      l.addEventListener("click", function () {
        try { foto = JSON.parse(l.getAttribute("data-foto")); } catch (e) { foto = [l.querySelector("img").src]; }
        nome = l.querySelector("strong") ? l.querySelector("strong").textContent : "";
        mostra(0);
        lb.classList.add("aperta");
        document.body.style.overflow = "hidden";
      });
    });
    function chiudi() { lb.classList.remove("aperta"); document.body.style.overflow = ""; }
    lb.querySelector(".chiudi").addEventListener("click", chiudi);
    lb.querySelector(".prec").addEventListener("click", function () { mostra(idx - 1); });
    lb.querySelector(".succ").addEventListener("click", function () { mostra(idx + 1); });
    lb.addEventListener("click", function (e) { if (e.target === lb) chiudi(); });
    document.addEventListener("keydown", function (e) {
      if (!lb.classList.contains("aperta")) return;
      if (e.key === "Escape") chiudi();
      if (e.key === "ArrowLeft") mostra(idx - 1);
      if (e.key === "ArrowRight") mostra(idx + 1);
    });
  }

  /* ---------- modulo contatti (invio senza ricaricare) ---------- */
  var modulo = document.querySelector("form.modulo");
  if (modulo) {
    var esito = modulo.querySelector(".esito");
    modulo.addEventListener("submit", function (e) {
      e.preventDefault();
      var bottone = modulo.querySelector("button[type=submit]");
      bottone.disabled = true; bottone.textContent = "Invio in corso…";
      esito.className = "esito";
      fetch(modulo.getAttribute("action"), { method: "POST", body: new FormData(modulo), headers: { "Accept": "application/json" } })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          esito.textContent = d.messaggio;
          esito.classList.add(d.ok ? "ok" : "errore");
          if (d.ok) modulo.reset();
        })
        .catch(function () {
          esito.textContent = "Non siamo riusciti a inviare il messaggio. Scrivici a info@ziwood.it o chiamaci.";
          esito.classList.add("errore");
        })
        .finally(function () { bottone.disabled = false; bottone.textContent = "Invia la richiesta"; });
    });
  }

  /* anno nel footer */
  var anno = document.querySelector("[data-anno]");
  if (anno) anno.textContent = new Date().getFullYear();
})();
