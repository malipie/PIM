/* Harmon PIM — interakcje strony (wersja statyczna, bez zależności). */
(function () {
  "use strict";
  var d = document;
  var reduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  // ---------- warunki (data-if) ----------
  var cond = {
    prodOpen: false, zasOpen: false, menuOpen: false, modalOpen: false,
    sent: false, notSent: true, modalSent: false, modalNotSent: true,
    uiOn: true, agOn: false,
  };
  for (var i = 0; i < 6; i++) {
    cond["fq" + i + "open"] = i === 0;
    cond["fq" + i + "shut"] = i !== 0;
  }
  function apply(name) {
    var on = cond[name];
    d.querySelectorAll('[data-if="' + name + '"]').forEach(function (el) {
      if (on) el.removeAttribute("hidden");
      else el.setAttribute("hidden", "");
    });
    d.querySelectorAll('[data-exp="' + name + '"]').forEach(function (el) {
      el.setAttribute("aria-expanded", on ? "true" : "false");
    });
    d.querySelectorAll('[data-faq-sign="' + name + '"]').forEach(function (el) {
      el.textContent = on ? "−" : "+";
    });
  }
  function set(patch) {
    for (var k in patch) {
      if (cond[k] === patch[k]) continue;
      cond[k] = patch[k];
      apply(k);
    }
    var lock = cond.modalOpen;
    if ((d.body.style.overflow === "hidden") !== lock) d.body.style.overflow = lock ? "hidden" : "";
  }

  // ---------- FAQ ----------
  var faqOpen = 0;
  function setFaq(n) {
    faqOpen = faqOpen === n ? -1 : n;
    var patch = {};
    for (var i = 0; i < 6; i++) {
      patch["fq" + i + "open"] = faqOpen === i;
      patch["fq" + i + "shut"] = faqOpen !== i;
    }
    set(patch);
  }

  // ---------- typy obiektów (landing) ----------
  var TYPES = [
    { name: "Produkt", kind: "typ systemowy", fields: "sku · ean · nazwa · cena · media · atrybuty", rel: "Produkt ⇄ Części zamienne", relB: "Produkt ⇄ Etykieta energetyczna" },
    { name: "Części zamienne", kind: "typ własny", fields: "numer katalogowy · zamienniki · dostępność · cena", rel: "Części zamienne ⇄ Produkt", relB: "Części zamienne ⇄ Dostawca" },
    { name: "Usługi", kind: "typ własny", fields: "zakres · czas realizacji · cennik · SLA", rel: "Usługi ⇄ Produkt", relB: "Usługi ⇄ Szkolenie" },
    { name: "Dostawcy", kind: "typ własny", fields: "NIP · warunki · cennik zakupowy · kontakt", rel: "Dostawcy ⇄ Produkt", relB: "Dostawcy ⇄ Części zamienne" },
    { name: "Etykiety energetyczne", kind: "typ własny", fields: "klasa · plik PDF · data ważności · EPREL", rel: "Etykiety ⇄ Produkt", relB: "Etykiety ⇄ Dostawca" },
    { name: "Budynki", kind: "typ własny", fields: "adres · powierzchnia · godziny · opiekun", rel: "Budynki ⇄ Produkt", relB: "Budynki ⇄ Szkolenie" },
    { name: "Szkolenia", kind: "typ własny", fields: "temat · poziom · termin · prowadzący", rel: "Szkolenia ⇄ Produkt", relB: "Szkolenia ⇄ Budynek" },
  ];
  function pickType(n) {
    var t = TYPES[n];
    if (!t) return;
    d.querySelectorAll("[data-type-tile]").forEach(function (el) {
      var on = +el.getAttribute("data-type-tile") === n;
      el.style.background = on ? "#ff4f00" : "rgba(255,255,255,.05)";
      el.style.borderColor = on ? "#ff4f00" : "rgba(255,255,255,.13)";
      el.setAttribute("aria-pressed", on ? "true" : "false");
      var spans = el.querySelectorAll("span");
      if (spans[0]) spans[0].style.color = on ? "#0b1220" : "#ffffff";
      if (spans[1]) spans[1].style.color = on ? "rgba(11,18,32,.6)" : "rgba(255,255,255,.42)";
    });
    var map = { name: t.name, kind: t.kind, fields: t.fields, rel: t.rel, relB: t.relB };
    d.querySelectorAll("[data-t]").forEach(function (el) {
      el.textContent = map[el.getAttribute("data-t")];
    });
  }

  // ---------- wysyłka formularzy (mail.php) ----------
  function pokazBlad(form) {
    var el = form.querySelector(".form-blad");
    if (!el) {
      el = d.createElement("p");
      el.className = "form-blad";
      el.style.cssText = "margin:12px 0 0;font-size:13.5px;line-height:1.5;color:#bd3a08";
      form.appendChild(el);
    }
    el.textContent = "Nie udało się wysłać zgłoszenia. Spróbuj ponownie za chwilę albo napisz na kontakt@harmonpim.pl.";
  }
  function wyslij(form, typ, poSukcesie) {
    var dane = new FormData(form);
    dane.append("formularz", typ);
    dane.append("_t", "harmon");
    var btn = form.querySelector('button[type="submit"]');
    var napis = btn ? btn.textContent : "";
    if (btn) { btn.disabled = true; btn.textContent = "Wysyłanie…"; }
    fetch("/mail.php", { method: "POST", body: dane })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.ok) throw new Error("mail");
        var blad = form.querySelector(".form-blad");
        if (blad) blad.remove();
        form.reset();
        poSukcesie();
      })
      .catch(function () { pokazBlad(form); })
      .finally(function () { if (btn) { btn.disabled = false; btn.textContent = napis; } });
  }

  // ---------- akcje ----------
  var actions = {
    openModal: function () { set({ modalOpen: true, modalSent: false, modalNotSent: true, menuOpen: false }); },
    closeModal: function () { set({ modalOpen: false }); },
    modalOverlayClick: function (e, el) { if (e.target === el) actions.closeModal(); },
    modalSubmit: function (e, el) {
      e.preventDefault();
      wyslij(el, "demo", function () { set({ modalSent: true, modalNotSent: false }); });
    },
    submitDemo: function (e, el) {
      e.preventDefault();
      wyslij(el, "kontakt", function () { set({ sent: true, notSent: false }); });
    },
    noSubmit: function (e) { e.preventDefault(); },
    openProd: function () { set({ prodOpen: true, zasOpen: false }); },
    closeProd: function () { set({ prodOpen: false }); },
    toggleProd: function () { set({ prodOpen: !cond.prodOpen }); },
    openZas: function () { set({ zasOpen: true, prodOpen: false }); },
    closeZas: function () { set({ zasOpen: false }); },
    toggleZas: function () { set({ zasOpen: !cond.zasOpen, prodOpen: false }); },
    toggleMenu: function () { set({ menuOpen: !cond.menuOpen }); },
    closeMenu: function () { set({ menuOpen: false }); },
    setUi: function () { set({ uiOn: true, agOn: false }); },
    setAgent: function () { set({ uiOn: false, agOn: true }); },
  };

  d.addEventListener("click", function (e) {
    var el = e.target.closest("[data-click],[data-pick]");
    if (!el) return;
    var pick = el.getAttribute("data-pick");
    if (pick !== null) return pickType(+pick);
    var name = el.getAttribute("data-click");
    if (name.indexOf("faq:") === 0) return setFaq(+name.slice(4));
    if (actions[name]) actions[name](e, el);
  });
  d.addEventListener("submit", function (e) {
    var el = e.target.closest("[data-submit]");
    if (el && actions[el.getAttribute("data-submit")]) actions[el.getAttribute("data-submit")](e, el);
  });
  // honeypot antyspamowy — niewidoczne pole, które wypełni tylko bot
  d.querySelectorAll('form[data-submit="modalSubmit"], form[data-submit="submitDemo"]').forEach(function (form) {
    var hp = d.createElement("input");
    hp.type = "text";
    hp.name = "www";
    hp.tabIndex = -1;
    hp.autocomplete = "off";
    hp.setAttribute("aria-hidden", "true");
    hp.style.cssText = "position:absolute;left:-9999px;width:1px;height:1px;opacity:0";
    form.appendChild(hp);
  });
  ["mouseenter", "mouseleave"].forEach(function (type) {
    d.querySelectorAll("[data-" + type + "]").forEach(function (el) {
      el.addEventListener(type, function (e) {
        var name = el.getAttribute("data-" + type);
        if (actions[name]) actions[name](e, el);
      });
    });
  });
  d.addEventListener("keydown", function (e) {
    if (e.key === "Escape") set({ prodOpen: false, zasOpen: false, modalOpen: false, menuOpen: false });
  });

  // ---------- reveal on scroll ----------
  (function () {
    var els = Array.prototype.slice.call(d.querySelectorAll("[data-reveal]"));
    if (!els.length) return;
    if (reduced) return; // elementy są widoczne domyślnie
    els.forEach(function (el) {
      el.style.opacity = "0";
      el.style.transform = "translateY(10px)";
      el.style.transition = "opacity .45s ease, transform .45s ease";
      el.style.transitionDelay = (parseInt(el.getAttribute("data-reveal"), 10) || 0) * 70 + "ms";
    });
    var pending = els;
    function show(el) { el.style.opacity = "1"; el.style.transform = "none"; }
    function tick() {
      if (!pending.length) return;
      var h = window.innerHeight || 800;
      pending = pending.filter(function (el) {
        var r = el.getBoundingClientRect();
        if (r.top < h - 24 && r.bottom > -80) { show(el); return false; }
        return true;
      });
    }
    window.addEventListener("scroll", tick, { passive: true });
    window.addEventListener("resize", tick);
    tick();
    requestAnimationFrame(function () { requestAnimationFrame(tick); });
    setTimeout(tick, 120);
    setTimeout(tick, 700);
    setTimeout(function () {
      pending.forEach(function (el) {
        if (el.getBoundingClientRect().top < (window.innerHeight || 800) * 1.2) show(el);
      });
      tick();
    }, 1600);
  })();

  // ---------- suwak porównania (landing) ----------
  (function () {
    var stage = d.querySelector("[data-stage]");
    if (!stage) return;
    var grip = d.querySelector("[data-grip]");
    var layer = stage.querySelector('[data-layer="agent"]');
    var handle = stage.querySelector("[data-handle]");
    var pos = 50, drag = false, raf = null, nudged = false;
    var narrowMq = window.matchMedia("(max-width: 767px)");

    function applyPos(anim) {
      if (layer) layer.style.transition = anim ? "clip-path .25s ease" : "none";
      if (handle) handle.style.transition = anim ? "left .25s ease" : "none";
      stage.style.setProperty("--pos", pos + "%");
      if (grip) {
        var v = Math.round(pos);
        grip.setAttribute("aria-valuenow", v);
        grip.setAttribute("aria-valuetext", v + "% interfejsu, " + (100 - v) + "% agenta");
      }
    }
    function setPos(p, anim) { pos = Math.max(2.4, Math.min(97.6, p)); applyPos(anim); }
    function posFrom(e) {
      var r = stage.getBoundingClientRect();
      return ((e.clientX - r.left) / r.width) * 100;
    }
    stage.addEventListener("pointerdown", function (e) {
      if (narrowMq.matches) return;
      if (raf) cancelAnimationFrame(raf);
      drag = true;
      try { stage.setPointerCapture(e.pointerId); } catch (err) {}
      setPos(posFrom(e), true);
    });
    stage.addEventListener("pointermove", function (e) { if (drag && !narrowMq.matches) setPos(posFrom(e), false); });
    stage.addEventListener("pointerup", function () { drag = false; });
    stage.addEventListener("pointercancel", function () { drag = false; });
    if (grip) grip.addEventListener("keydown", function (e) {
      var step = e.shiftKey ? 20 : 5;
      if (e.key === "ArrowLeft") { setPos(pos - step, true); e.preventDefault(); }
      else if (e.key === "ArrowRight") { setPos(pos + step, true); e.preventDefault(); }
      else if (e.key === "Home") { setPos(0, true); e.preventDefault(); }
      else if (e.key === "End") { setPos(100, true); e.preventDefault(); }
    });
    // delikatne "zakołysanie" suwaka przy pierwszym pokazaniu
    function runNudge() {
      if (drag || narrowMq.matches) return;
      var t = 0;
      (function step() {
        if (drag) return;
        t += 1;
        setPos(50 + 12 * Math.sin(t / 9), false);
        if (t < 57) raf = requestAnimationFrame(step);
        else setPos(50, false);
      })();
    }
    if (!reduced && !narrowMq.matches && "IntersectionObserver" in window) {
      var io = new IntersectionObserver(function (ents) {
        ents.forEach(function (en) {
          if (!en.isIntersecting || nudged) return;
          nudged = true;
          io.disconnect();
          setTimeout(runNudge, 420);
        });
      }, { threshold: 0.3 });
      io.observe(stage);
    }
    applyPos(false);
  })();

  // ---------- kafelki typów: animacja wejścia (landing) ----------
  (function () {
    var panel = d.querySelector("[data-types]");
    if (!panel || reduced) return;
    var tiles = Array.prototype.slice.call(panel.querySelectorAll("[data-tile]"));
    tiles.forEach(function (n) {
      n.style.opacity = "0";
      n.style.transform = "translateY(8px)";
      n.style.transition = "opacity .4s ease, transform .4s cubic-bezier(.2,.8,.2,1)";
    });
    function play() {
      tiles.forEach(function (n, i) {
        setTimeout(function () { n.style.opacity = "1"; n.style.transform = "none"; }, 60 + i * 70);
      });
    }
    function check() {
      var r = panel.getBoundingClientRect();
      var h = window.innerHeight || 800;
      if (r.top > h - 80 || r.bottom < 40) return;
      window.removeEventListener("scroll", check);
      window.removeEventListener("resize", check);
      play();
    }
    window.addEventListener("scroll", check, { passive: true });
    window.addEventListener("resize", check);
    setTimeout(check, 120);
  })();

  // ---------- Zgoda na cookies + Google Analytics (Consent Mode v2) ----------
  // Tryb ZAAWANSOWANY (advanced consent mode):
  //  1. gtag.js wczytuje się zawsze, niezależnie od decyzji użytkownika.
  //  2. Bez zgody analytics_storage = denied, czyli GA NIE zapisuje żadnych
  //     cookies ani trwałego identyfikatora — wysyła wyłącznie bezciasteczkowe
  //     trafienia, z których Google modeluje zagregowane statystyki.
  //     Dzięki temu „Tylko niezbędne" nadal daje obraz ruchu, a na urządzeniu
  //     użytkownika nic nie zostaje.
  //  3. Po „Akceptuję" analytics_storage przechodzi w granted i GA działa
  //     normalnie (cookie _ga, sesje, użytkownicy powracający).
  //  4. GA działa wyłącznie na produkcji; baner pokazuje się także lokalnie,
  //     żeby dało się go obejrzeć bez wdrażania.
  //  5. Loader stoi w tym pliku (self-hosted), a nie jako inline <script>,
  //     dzięki czemu CSP zostaje bez 'unsafe-inline' dla script-src.
  //     Domeny Google są dopuszczone w serwer/Caddyfile.
  (function () {
    var HOSTY = ["harmonpim.pl", "www.harmonpim.pl"];
    var NA_PRODUKCJI = HOSTY.indexOf(location.hostname) !== -1;
    var ID = "G-ZP9WLBSRGT";
    var KLUCZ = "harmon-zgoda-analityka"; // localStorage: "tak" | "nie"
    // Adres polityki prywatności. Pusty ⇒ link w banerze się nie pokazuje.
    // Po dodaniu podstrony wpisz tu np. "/prywatnosc/".
    var POLITYKA = "";

    function pamiec() { try { return localStorage.getItem(KLUCZ); } catch (e) { return null; } }
    function zapamietaj(v) { try { localStorage.setItem(KLUCZ, v); } catch (e) {} }

    window.dataLayer = window.dataLayer || [];
    function gtag() { window.dataLayer.push(arguments); }
    window.gtag = gtag;

    var wybor = pamiec(); // "tak" | "nie" | null

    // Stan wyjściowy. Gdy decyzja jest już znana z poprzedniej wizyty, ustawiamy
    // ją PROSTO W `default` i nie wysyłamy w ogóle `update`.
    // Dlaczego: `default` i `update` wypchnięte w tym samym cyklu, zanim
    // gtag.js zdąży się wczytać, powodują że GA gubi page_view i na kolejnych
    // odsłonach nie leci ani jedno trafienie (zmierzone: 0 na 3 podstronach).
    // Ścieżka „update po decyzji" zostaje wyłącznie dla kliknięcia w baner,
    // gdzie między default a update mija realny czas reakcji użytkownika.
    // Zapis samego wyboru (localStorage) jest niezbędny do działania serwisu,
    // więc nie wymaga zgody.
    var domyslne = {
      ad_storage: "denied",
      ad_user_data: "denied",
      ad_personalization: "denied",
      analytics_storage: wybor === "tak" ? "granted" : "denied",
      functionality_storage: "granted",
      security_storage: "granted",
    };
    // wait_for_update TYLKO gdy decyzji jeszcze nie ma — wtedy faktycznie
    // czekamy na kliknięcie. Przy zapamiętanym wyborze update już nie nadejdzie,
    // a zostawione oczekiwanie wstrzymuje pierwszy page_view i trafienie ginie.
    if (wybor !== "tak" && wybor !== "nie") domyslne.wait_for_update = 500;
    gtag("consent", "default", domyslne);

    var wczytany = false;
    function wczytajGA() {
      if (wczytany || !NA_PRODUKCJI) return;
      wczytany = true;
      var s = d.createElement("script");
      s.async = true;
      s.src = "https://www.googletagmanager.com/gtag/js?id=" + ID;
      d.head.appendChild(s);
      gtag("js", new Date());
      gtag("config", ID);
    }

    // ---- baner ----
    var baner = null;

    function el(tag, style, tekst) {
      var e = d.createElement(tag);
      if (style) e.setAttribute("style", style);
      if (tekst) e.textContent = tekst;
      return e;
    }

    function zamknij() {
      if (!baner) return;
      var b = baner;
      baner = null;
      if (reduced) { b.remove(); return; }
      b.style.transition = "opacity .18s ease, transform .18s ease";
      b.style.opacity = "0";
      b.style.transform = "translateY(12px)";
      setTimeout(function () { b.remove(); }, 200);
    }

    function decyzja(zgoda) {
      zapamietaj(zgoda ? "tak" : "nie");
      gtag("consent", "update", { analytics_storage: zgoda ? "granted" : "denied" });
      // Stan zgody trafia do dataLayer PRZED wczytaniem gtag.js, więc pierwszy
      // page_view od razu niesie właściwe gcs. Doszywanie page_view po fakcie
      // nie działa: GA gubi zdarzenie wysłane tuż po consent update (sprawdzone
      // — ten sam cykl, setTimeout 0 i 300 ms nie generują żadnego trafienia).
      wczytajGA();
      zamknij();
    }

    function pokazBaner() {
      if (baner) return;
      baner = el("div",
        "position:fixed;left:16px;right:16px;bottom:16px;z-index:90;margin:0 auto;" +
        "max-width:720px;background:#ffffff;border:1px solid #e3e9f1;border-radius:16px;" +
        "box-shadow:0 12px 40px rgba(14,24,48,.16);padding:20px 22px;" +
        "display:flex;flex-wrap:wrap;align-items:center;gap:16px 20px");
      baner.setAttribute("role", "dialog");
      baner.setAttribute("aria-label", "Zgoda na pliki cookies");

      var tresc = el("div", "flex:1 1 320px;min-width:0");
      tresc.appendChild(el("div",
        "font-size:15px;font-weight:600;color:#16233f;margin-bottom:6px",
        "Pliki cookies"));
      // Tekst celowo ogólny — mówi o kategoriach (niezbędne / analityczne
      // i marketingowe), a nie o konkretnych narzędziach. Dołożenie kolejnego
      // skryptu nie wymaga więc przepisywania banera.
      var opis = el("div", "font-size:13.5px;line-height:1.5;color:#5b6b87");
      opis.appendChild(d.createTextNode(
        "Używamy plików cookies i podobnych technologii, żeby serwis działał poprawnie " +
        "i żeby zrozumieć, jak z niego korzystasz. "));
      if (POLITYKA) {
        var a = el("a", "color:#bd3a08;text-decoration:underline", "Polityka prywatności");
        a.href = POLITYKA;
        opis.appendChild(a);
      }
      tresc.appendChild(opis);

      // Oba przyciski celowo tej samej wielkości i wagi — zgoda i odmowa
      // muszą być równie łatwe do kliknięcia (wymóg RODO/EROD).
      var wsp = "font-family:inherit;font-size:14px;font-weight:600;padding:11px 20px;" +
                "border-radius:10px;cursor:pointer;white-space:nowrap";
      var przyciski = el("div", "display:flex;gap:10px;flex:0 0 auto;flex-wrap:wrap");

      var odmow = el("button", wsp + ";background:#ffffff;color:#16233f;border:1px solid #cbd5e5",
        "Tylko niezbędne");
      odmow.type = "button";
      odmow.addEventListener("click", function () { decyzja(false); });

      var akceptuj = el("button", wsp + ";background:#ff4f00;color:#ffffff;border:1px solid #ff4f00",
        "Akceptuję");
      akceptuj.type = "button";
      akceptuj.addEventListener("click", function () { decyzja(true); });

      przyciski.appendChild(odmow);
      przyciski.appendChild(akceptuj);
      baner.appendChild(tresc);
      baner.appendChild(przyciski);

      if (!reduced) {
        baner.style.opacity = "0";
        baner.style.transform = "translateY(12px)";
      }
      d.body.appendChild(baner);
      if (!reduced) {
        requestAnimationFrame(function () {
          baner.style.transition = "opacity .22s ease, transform .22s ease";
          baner.style.opacity = "1";
          baner.style.transform = "translateY(0)";
        });
      }
    }

    // ---- „Ustawienia cookies" w stopce: możliwość zmiany decyzji ----
    function dodajPrzelacznik() {
      var stopka = d.querySelector("footer");
      if (!stopka) return;
      var prawa = null;
      stopka.querySelectorAll("span").forEach(function (s) {
        if (!prawa && /prawa zastrze/i.test(s.textContent)) prawa = s;
      });
      if (!prawa || !prawa.parentNode) return;
      var grupa = el("div", "display:flex;align-items:center;gap:18px;flex-wrap:wrap");
      prawa.parentNode.insertBefore(grupa, prawa);
      grupa.appendChild(prawa);
      var b = el("button",
        "font-family:inherit;font-size:13px;color:#5b6b87;background:none;border:0;" +
        "padding:0;cursor:pointer;text-decoration:underline",
        "Ustawienia cookies");
      b.type = "button";
      b.addEventListener("click", pokazBaner);
      grupa.appendChild(b);
    }

    if (wybor === "tak" || wybor === "nie") {
      // Decyzja z poprzedniej wizyty — stan siedzi już w `default` powyżej,
      // więc wystarczy wystartować GA.
      wczytajGA();
    } else {
      // Pierwsza wizyta: GA czeka na decyzję, żeby pierwszy page_view poleciał
      // z prawidłowym gcs. Odwiedzający, który zignoruje baner, nie zostanie
      // zmierzony — ale trafienia bez zgody i tak nie wchodzą do raportów,
      // więc realnie nic nie tracimy.
      pokazBaner();
    }
    dodajPrzelacznik();
  })();
})();
