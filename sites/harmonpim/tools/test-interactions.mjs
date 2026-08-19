/** Test interakcji zbudowanej strony (headless Chrome przez puppeteer-core). */
import puppeteer from "puppeteer-core";

const BASE = "http://localhost:8642";
const results = [];
const check = (name, ok, extra = "") => {
  results.push([ok ? "PASS" : "FAIL", name, extra].join("  "));
};

const browser = await puppeteer.launch({
  executablePath: "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome",
  headless: "new",
});
const page = await browser.newPage();
const errors = [];
page.on("pageerror", (e) => errors.push("pageerror: " + e.message));
page.on("console", (m) => { if (m.type() === "error") errors.push("console: " + m.text()); });

await page.setViewport({ width: 1440, height: 900 });
await page.goto(BASE + "/", { waitUntil: "networkidle0" });

// 1. Dropdown Produkt (hover otwiera, mouseleave zamyka — jak w prototypie)
await page.mouse.move(10, 500);
await page.hover('nav [data-mouseenter="openProd"]');
await new Promise((r) => setTimeout(r, 100));
let vis = await page.$eval('[data-if="prodOpen"]', (el) => !el.hidden);
check("dropdown Produkt otwiera się po hover", vis);
await page.mouse.move(10, 500);
await new Promise((r) => setTimeout(r, 100));
vis = await page.$eval('[data-if="prodOpen"]', (el) => !el.hidden);
check("dropdown zamyka się po zjechaniu myszą", !vis);

// 2. Dropdown z klawiatury (bez hovera): fokus + Enter → toggle, Escape zamyka
await page.evaluate(() => document.querySelector('nav [data-click="toggleProd"]').focus());
await page.keyboard.press("Enter");
vis = await page.$eval('[data-if="prodOpen"]', (el) => !el.hidden);
check("dropdown Produkt otwiera się z klawiatury", vis);
await page.keyboard.press("Escape");
vis = await page.$eval('[data-if="prodOpen"]', (el) => !el.hidden);
check("Escape zamyka dropdown", !vis);

// 3. Modal
await page.click('header [data-click="openModal"]');
vis = await page.$eval('[data-if="modalOpen"]', (el) => !el.hidden);
check("modal otwiera się z CTA", vis);
let overflow = await page.evaluate(() => document.body.style.overflow);
check("body ma zablokowany scroll przy modalu", overflow === "hidden");
// wypełnij i wyślij
await page.type('[data-if="modalOpen"] input[name="imie"]', "Test");
await page.type('[data-if="modalOpen"] input[name="email"]', "test@example.com");
await page.type('[data-if="modalOpen"] input[name="firma"]', "Testowa");
await page.click('[data-if="modalOpen"] input[type="checkbox"]');
await page.click('[data-if="modalOpen"] button[type="submit"]');
await new Promise((r) => setTimeout(r, 100));
vis = await page.$eval('[data-if="modalSent"]', (el) => !el.hidden);
check("po wysłaniu formularza widać potwierdzenie", vis);
await page.keyboard.press("Escape");

// 4. FAQ accordion
const q1 = await page.$$('[data-click="faq:1"]');
if (q1.length) {
  await q1[0].evaluate((el) => el.scrollIntoView({ block: "center" }));
  await q1[0].click();
  await new Promise((r) => setTimeout(r, 100));
  const open1 = await page.$eval('p[data-if="fq1open"]', (el) => !el.hidden);
  const closed0 = await page.$eval('p[data-if="fq0open"]', (el) => el.hidden);
  check("FAQ: klik otwiera panel 2 i zamyka panel 1", open1 && closed0);
}

// 5. Suwak — klawiatura
const grip = await page.$("[data-grip]");
if (grip) {
  await grip.evaluate((el) => el.scrollIntoView({ block: "center" }));
  await grip.focus();
  await page.keyboard.press("ArrowRight");
  await new Promise((r) => setTimeout(r, 400));
  const now = await page.$eval("[data-grip]", (el) => el.getAttribute("aria-valuenow"));
  check("suwak reaguje na strzałki (aria-valuenow=" + now + ")", now === "55" || +now > 50);
}

// 6. Kafelki typów
const tile3 = await page.$('[data-pick="3"]');
if (tile3) {
  await tile3.evaluate((el) => el.scrollIntoView({ block: "center" }));
  await tile3.click();
  await new Promise((r) => setTimeout(r, 100));
  const name = await page.$eval('[data-t="name"]', (el) => el.textContent);
  const pressed = await page.$eval('[data-pick="3"]', (el) => el.getAttribute("aria-pressed"));
  check("kafelki typów: klik zmienia szczegóły (" + name + ")", name === "Dostawcy" && pressed === "true");
}

// 7. Mobile: menu hamburger
await page.setViewport({ width: 390, height: 800 });
await page.goto(BASE + "/cennik/", { waitUntil: "networkidle0" });
const hamVisible = await page.$eval(".nav-ham", (el) => getComputedStyle(el).display !== "none");
const navHidden = await page.$eval("nav.nav-desktop, .nav-desktop", (el) => getComputedStyle(el).display === "none");
check("mobile: hamburger widoczny, nawigacja ukryta", hamVisible && navHidden);
await page.click(".nav-ham");
vis = await page.$eval('[data-if="menuOpen"]', (el) => !el.hidden);
check("mobile: menu otwiera się", vis);
const linkVis = await page.$eval('[data-if="menuOpen"] a[href="/produkt/"]', (el) => el.offsetParent !== null);
check("mobile: linki menu klikalne", linkVis);

// 8. Kontakt: formularz strony
await page.setViewport({ width: 1440, height: 900 });
await page.goto(BASE + "/kontakt/", { waitUntil: "networkidle0" });
const form = await page.$('[data-submit="submitDemo"]');
if (form) {
  await page.evaluate(() => {
    document.querySelectorAll('[data-submit="submitDemo"] input[required], [data-submit="submitDemo"] textarea[required]').forEach((i) => {
      if (i.type === "checkbox") i.checked = true;
      else i.value = i.type === "email" ? "a@b.pl" : "x";
    });
  });
  await page.click('[data-submit="submitDemo"] button[type="submit"]');
  await new Promise((r) => setTimeout(r, 100));
  const sentVis = await page.$$eval('[data-if="sent"]', (els) => els.some((el) => !el.hidden));
  check("kontakt: formularz pokazuje potwierdzenie", sentVis);
}

// 9. Zasoby: przycisk rozwija dropdown (bez nawigacji), linki prowadzą do docs
await page.goto(BASE + "/", { waitUntil: "networkidle0" });
const urlBefore = page.url();
await page.evaluate(() => document.querySelector('nav [data-click="toggleZas"]').focus());
await page.keyboard.press("Enter");
await new Promise((r) => setTimeout(r, 150));
const zasOpen = await page.$eval('[data-if="zasOpen"]', (el) => !el.hidden);
check("Zasoby: klik rozwija dropdown bez nawigacji", zasOpen && page.url() === urlBefore);
const docsHrefs = await page.$$eval('[data-if="zasOpen"] a', (as) => as.map((a) => a.getAttribute("href")));
check(
  "Zasoby: 4 linki do docs w aplikacji",
  docsHrefs.length === 4 && docsHrefs.every((h) => h.startsWith("https://app.harmonpim.pl/docs/")),
  docsHrefs.join(" ")
);
await page.keyboard.press("Escape");

// 10. Wszystkie strony: brak błędów JS + jest H1 + canonical
for (const path of ["/", "/produkt/", "/funkcje/", "/integracje/", "/wdrozenie/", "/cennik/", "/kontakt/"]) {
  await page.goto(BASE + path, { waitUntil: "networkidle0" });
  const h1 = await page.$$eval("h1", (els) => els.length);
  const canonical = await page.$eval('link[rel="canonical"]', (el) => el.href);
  const title = await page.title();
  check("strona " + path, h1 === 1 && canonical.endsWith(path) && title.length > 10, "(h1=" + h1 + ")");
}

check("brak błędów JS w konsoli", errors.length === 0, errors.join(" | ").slice(0, 300));

await browser.close();
console.log(results.join("\n"));
