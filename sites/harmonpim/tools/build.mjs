/**
 * Konwersja prototypu Claude Design (.dc.html + support.js) na statyczny HTML.
 * Wejście: *.dc.html w katalogu głównym. Wyjście: dist/.
 */
import { promises as fs } from "node:fs";
import path from "node:path";
import * as cheerio from "cheerio";

const ROOT = path.resolve(import.meta.dirname, "..");
const DIST = path.join(ROOT, "dist");
const SITE = "https://harmonpim.pl"; // ← domena produkcyjna (jedno miejsce do zmiany)
const SITE_NAME = "Harmon PIM";
const LOGIN_URL = "https://app.harmonpim.pl/"; // przyciski „Zaloguj się" (nowa karta)

const PAGES = [
  { src: "Harmon PIM - Landing.dc.html", out: "index.html", url: "/", crumb: null },
  { src: "Produkt.dc.html", out: "produkt/index.html", url: "/produkt/", crumb: "Przegląd produktu" },
  { src: "Funkcje.dc.html", out: "funkcje/index.html", url: "/funkcje/", crumb: "Funkcje" },
  { src: "Integracje.dc.html", out: "integracje/index.html", url: "/integracje/", crumb: "Integracje" },
  { src: "Wdrozenie.dc.html", out: "wdrozenie/index.html", url: "/wdrozenie/", crumb: "Wdrożenie PIM" },
  { src: "Cennik.dc.html", out: "cennik/index.html", url: "/cennik/", crumb: "Cennik" },
  { src: "Kontakt.dc.html", out: "kontakt/index.html", url: "/kontakt/", crumb: "Kontakt" },
];

const LINK_MAP = {
  "Harmon PIM - Landing.dc.html": "/",
  "Produkt.dc.html": "/produkt/",
  "Funkcje.dc.html": "/funkcje/",
  "Integracje.dc.html": "/integracje/",
  "Wdrozenie.dc.html": "/wdrozenie/",
  "Cennik.dc.html": "/cennik/",
  "Kontakt.dc.html": "/kontakt/",
};

// Zasoby: podstrona /zasoby/ nie istnieje — linki prowadzą do dokumentacji w aplikacji
const DOCS_LINKS = {
  "Zasoby.dc.html#selfhost": "https://app.harmonpim.pl/docs/selfhost",
  "Zasoby.dc.html#dokumentacja": "https://app.harmonpim.pl/docs/guide/feeds.html",
  "Zasoby.dc.html#api": "https://app.harmonpim.pl/docs/api.html#description/introduction",
  "Zasoby.dc.html#licencja": "https://app.harmonpim.pl/docs/licence",
};

// Wymiary oryginałów (do width/height na <img>)
const IMG_DIMS = {
  "harmon-agent-chat": [896, 1292],
  "harmon-agent-panel": [2932, 1468],
  "harmon-bulk-action": [2932, 1468],
  "harmon-dam-grid": [1024, 506],
  "harmon-dashboard": [1888, 1225],
  "harmon-workspace": [2940, 1448],
  "produkt-agd": [1024, 455],
  "produkt-meble": [1024, 455],
  "produkt-moda": [1024, 455],
  "produkt-narzedzia": [1024, 455],
};
const IMG_1200 = new Set(["harmon-workspace", "harmon-agent-panel", "harmon-bulk-action", "harmon-dashboard"]);

// Warunki sc-if: wartości początkowe. true/false → element z data-if + hidden gdy false.
// "unwrap" → zawsze widoczny, rozpakowany bez znacznika.
const COND_INITIAL = {
  prodOpen: false, zasOpen: false, menuOpen: false, modalOpen: false,
  sent: false, notSent: true, agOn: false, uiOn: true,
  modalSent: false, modalNotSent: true,
  true: "unwrap", badge: "unwrap", pokazFaq: "unwrap", pokazIndeks: "unwrap",
  pokazLogotypy: "unwrap", pokazZespol: "unwrap",
  deskSlider: "slider-desk", mobSlider: "slider-mob", // sterowane media query
};
for (let i = 0; i < 6; i++) {
  COND_INITIAL["fq" + i + "open"] = i === 0;
  COND_INITIAL["fq" + i + "shut"] = i !== 0;
}

// Dziury {{ x }} w atrybutach zwykłych (nie-event): wartość początkowa + opcjonalny data-hook
const ATTR_HOLES = {
  navDisp: { value: "flex", cls: "nav-desktop" },
  hamDisp: { value: "none", cls: "nav-ham" },
  prodExp: { value: "false", hook: ["data-exp", "prodOpen"] },
  zasExp: { value: "false", hook: ["data-exp", "zasOpen"] },
  menuOpen: { value: "false", hook: ["data-exp", "menuOpen"] }, // aria-expanded na hamburgerze
};
for (let i = 0; i < 6; i++) ATTR_HOLES["fq" + i + "exp"] = { value: i === 0 ? "true" : "false", hook: ["data-exp", "fq" + i + "open"] };

// Typy obiektów (landing) — initial typeIdx = 1
const TYPES = [
  { name: "Produkt", kind: "typ systemowy", fields: "sku · ean · nazwa · cena · media · atrybuty", rel: "Produkt ⇄ Części zamienne", relB: "Produkt ⇄ Etykieta energetyczna" },
  { name: "Części zamienne", kind: "typ własny", fields: "numer katalogowy · zamienniki · dostępność · cena", rel: "Części zamienne ⇄ Produkt", relB: "Części zamienne ⇄ Dostawca" },
  { name: "Usługi", kind: "typ własny", fields: "zakres · czas realizacji · cennik · SLA", rel: "Usługi ⇄ Produkt", relB: "Usługi ⇄ Szkolenie" },
  { name: "Dostawcy", kind: "typ własny", fields: "NIP · warunki · cennik zakupowy · kontakt", rel: "Dostawcy ⇄ Produkt", relB: "Dostawcy ⇄ Części zamienne" },
  { name: "Etykiety energetyczne", kind: "typ własny", fields: "klasa · plik PDF · data ważności · EPREL", rel: "Etykiety ⇄ Produkt", relB: "Etykiety ⇄ Dostawca" },
  { name: "Budynki", kind: "typ własny", fields: "adres · powierzchnia · godziny · opiekun", rel: "Budynki ⇄ Produkt", relB: "Budynki ⇄ Szkolenie" },
  { name: "Szkolenia", kind: "typ własny", fields: "temat · poziom · termin · prowadzący", rel: "Szkolenia ⇄ Produkt", relB: "Szkolenia ⇄ Budynek" },
];
const TYPE_IDX0 = 1;
const typeStyle = (on) => ({
  bg: on ? "#ff4f00" : "rgba(255,255,255,.05)",
  bd: on ? "#ff4f00" : "rgba(255,255,255,.13)",
  fg: on ? "#0b1220" : "#ffffff",
  cg: on ? "rgba(11,18,32,.6)" : "rgba(255,255,255,.42)",
});

const ALT_SLOT = {
  "produkt-narzedzia": "Strona katalogu Web to Print - branża narzędziowa",
  "produkt-agd": "Strona katalogu Web to Print - AGD",
  "produkt-moda": "Strona katalogu Web to Print - moda",
  "produkt-meble": "Strona katalogu Web to Print - meble",
};

// ---------- rejestr klas hover/focus ----------
const hoverClasses = new Map(); // css -> className
function classFor(css, kind) {
  const key = kind + "|" + css;
  if (!hoverClasses.has(key)) hoverClasses.set(key, (kind === "hover" ? "h" : "f") + hoverClasses.size.toString(36));
  return hoverClasses.get(key);
}
function important(css) {
  return css.split(";").map((d) => d.trim()).filter(Boolean).map((d) => d + " !important").join(";");
}

// ---------- pomocnicze ----------
const holeName = (v) => {
  const m = /^\{\{\s*([a-zA-Z0-9_]+)\s*\}\}$/.exec(v || "");
  return m ? m[1] : null;
};

function rewriteHref(href) {
  if (!href) return href;
  if (DOCS_LINKS[href]) return DOCS_LINKS[href];
  const m = /^([^#]+?)(#.*)?$/.exec(href);
  if (!m) return href;
  const base = m[1], hash = m[2] || "";
  const mapped = LINK_MAP[decodeURIComponent(base)];
  return mapped ? mapped + hash : href;
}

/** „Zasoby" w nagłówku: link → przycisk rozwijający (jak „Produkt");
 *  „Zasoby" w menu mobilnym: link → nagłówek z wciętą listą 4 pozycji docs. */
function transformZasobyNav($, $root) {
  // nagłówek — przejmij style z przycisku „Produkt"
  const $prodBtn = $root.find('button[onclick="{{ toggleProd }}"]').first();
  $root.find('a[href="Zasoby.dc.html"][aria-haspopup]').each((_, el) => {
    const $el = $(el);
    const $btn = $("<button></button>")
      .attr("type", "button")
      .attr("onclick", "{{ toggleZas }}")
      .attr("aria-haspopup", "true")
      .attr("aria-expanded", "{{ zasExp }}")
      .attr("style", $prodBtn.attr("style"))
      .attr("style-hover", $prodBtn.attr("style-hover"))
      .attr("style-focus", $prodBtn.attr("style-focus"))
      .append($el.contents());
    $el.replaceWith($btn);
  });
  // menu mobilne
  const subLink = (href, label) =>
    `<a href="${href}" onclick="{{ closeMenu }}" style="padding:9px 0;font-size:15px;font-weight:500;color:#5b6b87;text-decoration:none">${label}</a>`;
  $root.find('a[href="Zasoby.dc.html"]').not("[aria-haspopup]").each((_, el) => {
    $(el).replaceWith(
      `<div style="padding:14px 2px 10px;font-size:16px;font-weight:600;color:#16233f">Zasoby</div>
<div style="display:flex;flex-direction:column;padding:0 0 12px 14px;border-bottom:1px solid #eef2f7;border-left:2px solid #ffd0bd;margin-left:2px">
${subLink("Zasoby.dc.html#selfhost", "Self-host Harmon")}
${subLink("Zasoby.dc.html#dokumentacja", "Dokumentacja")}
${subLink("Zasoby.dc.html#api", "Dokumentacja API")}
${subLink("Zasoby.dc.html#licencja", "Licencja")}
</div>`
    );
  });
}

const EVENT_ATTRS = {
  onclick: "data-click",
  onmouseenter: "data-mouseenter",
  onmouseleave: "data-mouseleave",
  onsubmit: "data-submit",
  onkeydown: "data-keydown",
  onpointerdown: "data-pointerdown",
  onpointermove: "data-pointermove",
  onpointerup: "data-pointerup",
  onpointercancel: "data-pointercancel",
};

function transformBody($, $root, { isModal = false, pageIsFirstImgEager = true } = {}) {
  // 1) sc-if — od najgłębszych (przetwarzamy aż nie zostanie żaden)
  let guard = 0;
  while (guard++ < 200) {
    const scifs = $root.find("sc-if").toArray();
    if (!scifs.length) break;
    // wybierz takie, które nie zawierają zagnieżdżonych sc-if
    for (const el of scifs) {
      const $el = $(el);
      if ($el.find("sc-if").length) continue;
      let cond = holeName($el.attr("value"));
      if (isModal) {
        if (cond === "sent") cond = "modalSent";
        if (cond === "notSent") cond = "modalNotSent";
      }
      const init = COND_INITIAL[cond];
      if (init === undefined) throw new Error("Nieznany warunek sc-if: " + cond);
      const children = $el.children();
      const hasText = $el.contents().toArray().some((n) => n.type === "text" && n.data.trim());
      if (init === "unwrap") {
        $el.replaceWith($el.contents());
        continue;
      }
      const isSliderCls = typeof init === "string"; // slider-desk / slider-mob
      if (children.length === 1 && !hasText) {
        const $c = $(children[0]);
        if (isSliderCls) {
          $c.addClass(init);
        } else {
          $c.attr("data-if", cond);
          if (!init) $c.attr("hidden", "");
        }
        $el.replaceWith($c);
      } else {
        const $w = $("<div></div>").attr("style", "display:contents").append($el.contents());
        if (isSliderCls) $w.addClass(init);
        else {
          $w.attr("data-if", cond);
          if (!init) $w.attr("hidden", "");
        }
        $el.replaceWith($w);
      }
    }
  }

  // 2) wszystkie elementy: atrybuty
  let firstImg = true;
  $root.find("*").each((_, el) => {
    const $el = $(el);
    const attribs = { ...el.attribs };

    // eventy
    for (const [attr, dataAttr] of Object.entries(EVENT_ATTRS)) {
      if (attr in attribs) {
        let name = holeName(attribs[attr]);
        if (name) {
          if (isModal) {
            if (name === "close") name = "closeModal";
            if (name === "overlayClick") name = "modalOverlayClick";
            if (name === "submitDemo") name = "modalSubmit";
          }
          const pick = /^pick(\d)$/.exec(name);
          if (pick) $el.attr("data-pick", pick[1]);
          else if (/^fq(\d)t$/.exec(name)) $el.attr("data-click", "faq:" + name[2]);
          else $el.attr(dataAttr, name);
        }
        $el.removeAttr(attr);
      }
    }
    // refy
    if (holeName(attribs.ref) === "stageRef") $el.attr("data-stage", "");
    if (holeName(attribs.ref) === "gripRef") $el.attr("data-grip", "");
    $el.removeAttr("ref");

    // hover/focus
    if (attribs["style-hover"]) {
      $el.addClass(classFor(important(attribs["style-hover"]), "hover"));
      $el.removeAttr("style-hover");
    }
    if (attribs["style-focus"]) {
      $el.addClass(classFor(important(attribs["style-focus"]), "focus"));
      $el.removeAttr("style-focus");
    }

    // porządki
    for (const junk of ["hint-placeholder-val", "hint-size", "data-screen-label"]) $el.removeAttr(junk);

    // zwykłe atrybuty z dziurami
    for (const [attr, val] of Object.entries($el.attr() || {})) {
      const name = holeName(val);
      if (!name || attr.startsWith("data-")) continue;
      if (attr === "on-close") { $el.removeAttr(attr); continue; } // prop dc-import, zbędny po inlinie
      const spec = ATTR_HOLES[name];
      if (spec) {
        $el.attr(attr, spec.value);
        if (spec.cls) $el.addClass(spec.cls);
        if (spec.hook) $el.attr(spec.hook[0], spec.hook[1]);
      } else if (/^on(\d)$/.exec(name)) {
        const i = +RegExp.$1;
        $el.attr(attr, String(i === TYPE_IDX0));
      } else if (/^fq(\d)sign$/.exec(name)) {
        // obsłużone niżej jako tekst
      } else {
        throw new Error("Nieobsłużona dziura w atrybucie " + attr + ": " + name);
      }
    }

    // style z dziurami typów: bgN/bdN/fgN/cgN
    let style = $el.attr("style");
    if (style && style.includes("{{")) {
      style = style.replace(/\{\{\s*(bg|bd|fg|cg)(\d)\s*\}\}/g, (_, p, n) => {
        $el.attr("data-type-tile", n);
        return typeStyle(+n === TYPE_IDX0)[p];
      });
      style = style.replace(/\{\{\s*(navDisp|hamDisp)\s*\}\}/g, (_, n) => {
        const spec = ATTR_HOLES[n];
        $el.addClass(spec.cls);
        return spec.value;
      });
      if (style.includes("{{")) throw new Error("Nieobsłużona dziura w style: " + style);
      $el.attr("style", style);
    }

    // linki
    if (el.tagName === "a" && attribs.href) {
      const nowy = rewriteHref(attribs.href);
      $el.attr("href", nowy);
      // dokumentacja w aplikacji — nowa karta
      if (nowy.startsWith("https://app.harmonpim.pl/docs/")) {
        $el.attr("target", "_blank").attr("rel", "noopener");
      }
    }

    // „Zaloguj się" (header + menu mobilne) → aplikacja PIM w nowej karcie
    if (el.tagName === "a" && attribs.href === "#" && $el.text().trim() === "Zaloguj się") {
      $el.attr("href", LOGIN_URL).attr("target", "_blank").attr("rel", "noopener");
    }

    // obrazki
    if (el.tagName === "img") {
      const src = $el.attr("src") || "";
      const fav = /favicons\?sz=\d+&(?:amp;)?domain=([a-z0-9.-]+)/.exec(src);
      const asset = /^assets\/([a-z0-9-]+)\.png$/i.exec(src);
      if (fav) {
        $el.attr("src", "/assets/logos/" + fav[1] + ".png");
      } else if (asset) {
        const base = asset[1];
        $el.attr("src", "/assets/img/" + base + ".webp");
        const dims = IMG_DIMS[base];
        if (dims) { $el.attr("width", dims[0]); $el.attr("height", dims[1]); }
        if (IMG_1200.has(base)) {
          $el.attr("srcset", `/assets/img/${base}-1200.webp 1200w, /assets/img/${base}.webp ${dims[0]}w`);
          $el.attr("sizes", "(max-width: 1100px) 100vw, 1040px");
        }
        if (firstImg && pageIsFirstImgEager) {
          $el.attr("fetchpriority", "high");
          $el.attr("decoding", "async");
        } else {
          $el.attr("loading", "lazy");
          $el.attr("decoding", "async");
        }
      }
      if (!$el.attr("loading") && !fav && !asset) $el.attr("decoding", "async");
      if (asset) firstImg = false;
      // logotypy: assets/logos/x.webp → ścieżka absolutna (strony żyją w podkatalogach)
      const src2 = $el.attr("src") || "";
      if (src2.startsWith("assets/")) $el.attr("src", "/" + src2);
    }

    // image-slot → img
    if (el.tagName === "image-slot") {
      const m = /assets\/([a-z0-9-]+)\.png/.exec($el.attr("src") || "");
      const base = m ? m[1] : null;
      const dims = base ? IMG_DIMS[base] : null;
      const $img = $("<img>")
        .attr("src", "/assets/img/" + base + ".webp")
        .attr("alt", ALT_SLOT[base] || $el.attr("placeholder") || "")
        .attr("loading", "lazy").attr("decoding", "async")
        .attr("style", `position:absolute;inset:0;width:100%;height:100%;object-fit:cover;border-radius:${$el.attr("radius") || 8}px`);
      if (dims) { $img.attr("width", dims[0]); $img.attr("height", dims[1]); }
      $el.replaceWith($img);
    }
  });

  // 3) dziury tekstowe
  $root.find("*").each((_, el) => {
    for (const node of el.children || []) {
      if (node.type !== "text" || !node.data.includes("{{")) continue;
      node.data = node.data.replace(/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/g, (_, name) => {
        const t = TYPES[TYPE_IDX0];
        const map = { tName: t.name, tKind: t.kind, tFields: t.fields, tRel: t.rel, tRelB: t.relB };
        if (name in map) {
          $(el).attr("data-t", { tName: "name", tKind: "kind", tFields: "fields", tRel: "rel", tRelB: "relB" }[name]);
          return map[name];
        }
        const sign = /^fq(\d)sign$/.exec(name);
        if (sign) {
          $(el).attr("data-faq-sign", "fq" + sign[1] + "open");
          return +sign[1] === 0 ? "−" : "+";
        }
        throw new Error("Nieobsłużona dziura tekstowa: " + name);
      });
    }
  });
}

// ---------- modal (Formularz-demo) ----------
async function buildModalFragment() {
  const raw = await fs.readFile(path.join(ROOT, "Formularz-demo.dc.html"), "utf8");
  const $ = cheerio.load(raw.replace(/<helmet(\s|>)/gi, "<sc-helmet$1").replace(/<\/helmet\s*>/gi, "</sc-helmet>"));
  const $x = $("x-dc");
  transformBody($, $x, { isModal: true });
  const $overlay = $x.children().first();
  $overlay.attr("data-if", "modalOpen").attr("hidden", "");
  return $.html($overlay);
}

// ---------- head ----------
function buildHead($, $helmet, page, extraLd) {
  const title = $helmet.find("title").first().text().trim();
  const desc = $helmet.find('meta[name="description"]').attr("content") || "";
  const ogTitle = $helmet.find('meta[property="og:title"]').attr("content") || title;
  const ogDesc = $helmet.find('meta[property="og:description"]').attr("content") || desc;
  const lds = $helmet.find('script[type="application/ld+json"]').toArray().map((s) => $(s).text().trim());
  const url = SITE + page.url;
  const ogImg = SITE + "/assets/og/" + (page.out === "index.html" ? "landing" : page.url.replaceAll("/", "")) + ".png";

  const head = [
    `<meta charset="utf-8">`,
    `<meta name="viewport" content="width=device-width, initial-scale=1">`,
    `<title>${title}</title>`,
    `<meta name="description" content="${desc.replace(/"/g, "&quot;")}">`,
    `<link rel="canonical" href="${url}">`,
    `<meta name="robots" content="index, follow, max-image-preview:large">`,
    `<meta name="theme-color" content="#0e1830">`,
    `<meta property="og:site_name" content="${SITE_NAME}">`,
    `<meta property="og:locale" content="pl_PL">`,
    `<meta property="og:type" content="website">`,
    `<meta property="og:url" content="${url}">`,
    `<meta property="og:title" content="${ogTitle.replace(/"/g, "&quot;")}">`,
    `<meta property="og:description" content="${ogDesc.replace(/"/g, "&quot;")}">`,
    `<meta property="og:image" content="${ogImg}">`,
    `<meta property="og:image:width" content="1200">`,
    `<meta property="og:image:height" content="630">`,
    `<meta name="twitter:card" content="summary_large_image">`,
    `<meta name="twitter:title" content="${ogTitle.replace(/"/g, "&quot;")}">`,
    `<meta name="twitter:description" content="${ogDesc.replace(/"/g, "&quot;")}">`,
    `<meta name="twitter:image" content="${ogImg}">`,
    `<link rel="icon" href="/favicon.svg" type="image/svg+xml">`,
    `<link rel="apple-touch-icon" href="/apple-touch-icon.png">`,
    `<link rel="sitemap" type="application/xml" href="/sitemap.xml">`,
    ...["inter-var-latin", "inter-var-latin-ext"].map(
      (f) => `<link rel="preload" href="/assets/fonts/${f}.woff2" as="font" type="font/woff2" crossorigin>`
    ),
    `<link rel="stylesheet" href="/assets/css/main.css">`,
    ...lds.map((ld) => `<script type="application/ld+json">${ld}</script>`),
    ...extraLd.map((ld) => `<script type="application/ld+json">${JSON.stringify(ld)}</script>`),
    `<script src="/assets/js/site.js" defer></script>`,
  ];
  return head.join("\n");
}

// ---------- main ----------
const modalFragment = await buildModalFragment();
let baseCss = null;

for (const page of PAGES) {
  const raw = await fs.readFile(path.join(ROOT, page.src), "utf8");
  const $ = cheerio.load(raw.replace(/<helmet(\s|>)/gi, "<sc-helmet$1").replace(/<\/helmet\s*>/gi, "</sc-helmet>"));
  const $x = $("x-dc");
  const $helmet = $x.find("sc-helmet").first();

  // CSS bazowy z helmetu (identyczny na wszystkich stronach — zweryfikuj)
  // CSS bazowy: landing (pierwsza strona) jest nadzbiorem pozostałych (dodatkowo @keyframes blink i selecty)
  const css = $helmet.find("style").toArray().map((s) => $(s).text()).join("\n").trim();
  if (baseCss === null) baseCss = css;

  $helmet.remove();
  transformZasobyNav($, $x);
  transformBody($, $x, { isModal: false, pageIsFirstImgEager: page.url === "/" });

  const extraLd = [];
  if (page.url === "/") {
    extraLd.push({
      "@context": "https://schema.org", "@type": "WebSite",
      name: SITE_NAME, url: SITE + "/", inLanguage: "pl-PL",
    });
  } else {
    extraLd.push({
      "@context": "https://schema.org", "@type": "BreadcrumbList",
      itemListElement: [
        { "@type": "ListItem", position: 1, name: "Start", item: SITE + "/" },
        { "@type": "ListItem", position: 2, name: page.crumb, item: SITE + page.url },
      ],
    });
  }

  // FAQPage z markupu (o ile helmet nie zawiera już własnego)
  const hasFaqLd = $helmet.find('script[type="application/ld+json"]').toArray()
    .some((s) => $(s).text().includes('"FAQPage"'));
  if (!hasFaqLd) {
    const faq = [];
    $x.find('[data-click^="faq:"]').each((_, btn) => {
      const n = $(btn).attr("data-click").slice(4);
      const q = $(btn).find("span").first().text().trim();
      const a = $x.find(`p[data-if="fq${n}open"]`).first().text().trim();
      if (q && a) faq.push({
        "@type": "Question", name: q,
        acceptedAnswer: { "@type": "Answer", text: a },
      });
    });
    if (faq.length) extraLd.push({ "@context": "https://schema.org", "@type": "FAQPage", mainEntity: faq });
  }

  if (page.url === "/wdrozenie/") {
    extraLd.push({
      "@context": "https://schema.org", "@type": "Service",
      name: "Wdrożenie PIM",
      serviceType: "Wdrożenie systemu PIM (Product Information Management)",
      description: "Wdrożenie PIM prowadzone przez zespół Harmon: konsultant wdrożeniowy, architekt struktury danych, integrator i opiekun po starcie. Cztery etapy - od struktury danych po integracje.",
      provider: { "@type": "Organization", name: SITE_NAME, url: SITE },
      areaServed: "PL",
      url: SITE + page.url,
    });
  }

  if (page.url === "/cennik/") {
    extraLd.push({
      "@context": "https://schema.org", "@type": "SoftwareApplication",
      name: SITE_NAME, applicationCategory: "BusinessApplication", operatingSystem: "Web",
      description: "System zarządzania informacją produktową (PIM) z agentem AI - self-host albo chmura.",
      offers: [
        { "@type": "Offer", name: "Self-Host", price: "0", priceCurrency: "PLN" },
        { "@type": "Offer", name: "Cloud", price: "1000", priceCurrency: "PLN" },
        { "@type": "Offer", name: "Cloud Pro", price: "3000", priceCurrency: "PLN" },
      ],
    });
  }

  const headHtml = buildHead($, $helmet, page, extraLd);

  // dc-import (modal) — sc-if został już przetworzony; znajdź placeholder
  $x.find("dc-import").each((_, el) => {
    const $el = $(el);
    const $parent = $el.parent();
    if ($parent.attr("data-if") === "modalOpen") $parent.replaceWith(modalFragment);
    else $el.replaceWith(modalFragment); // data-if wylądował na samym dc-import
  });

  // aktywna pozycja nawigacji (podkreślenie robi prototyp przez style — sprawdź czy jest w źródle; nic nie robimy)

  const bodyHtml = $x.html();
  const doc = `<!DOCTYPE html>
<html lang="pl">
<head>
${headHtml}
</head>
<body>
${bodyHtml}
</body>
</html>
`;
  const outPath = path.join(DIST, page.out);
  await fs.mkdir(path.dirname(outPath), { recursive: true });
  await fs.writeFile(outPath, doc);
  console.log("✓", page.out, (doc.length / 1024).toFixed(0) + " KB");
}

// ---------- main.css ----------
const fontsCss = await fs.readFile(path.join(ROOT, "tools", "fonts.css"), "utf8");
const hoverCss = [...hoverClasses.entries()]
  .map(([key, cls]) => {
    const [kind, css] = [key.slice(0, key.indexOf("|")), key.slice(key.indexOf("|") + 1)];
    return kind === "hover" ? `.${cls}:hover{${css}}` : `.${cls}:focus-visible{${css}}`;
  })
  .join("\n");

const layoutCss = `
[hidden]{display:none !important}
.slider-mob{display:none}
@media (max-width: 767px){
  .slider-desk{display:none !important}
  .slider-mob{display:block}
}
@media (max-width: 1120px){
  .nav-desktop{display:none !important}
  .nav-ham{display:inline-flex !important}
}
`;
await fs.mkdir(path.join(DIST, "assets/css"), { recursive: true });
await fs.writeFile(path.join(DIST, "assets/css/main.css"), [fontsCss, baseCss, layoutCss, hoverCss].join("\n"));
console.log("✓ assets/css/main.css");

// pliki statyczne (site.js, robots, sitemap, .htaccess, 404, favicony)
await fs.cp(path.join(ROOT, "static"), DIST, { recursive: true });
// katalog roboczy z oryginałami logotypów nie idzie na serwer
await fs.rm(path.join(DIST, "assets/logos/new"), { recursive: true, force: true });
console.log("✓ static/ → dist/");
