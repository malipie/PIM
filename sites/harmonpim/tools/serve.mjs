/** Podgląd dist/ z kompresją gzip (jak na produkcji): node tools/serve.mjs [port] */
import http from "node:http";
import { promises as fs } from "node:fs";
import path from "node:path";
import zlib from "node:zlib";

const DIST = path.resolve(import.meta.dirname, "..", "dist");
const PORT = +(process.argv[2] || 8642);
const MIME = {
  ".html": "text/html; charset=utf-8", ".css": "text/css", ".js": "application/javascript",
  ".webp": "image/webp", ".png": "image/png", ".svg": "image/svg+xml",
  ".woff2": "font/woff2", ".xml": "application/xml", ".txt": "text/plain",
};
const COMPRESS = new Set([".html", ".css", ".js", ".svg", ".xml", ".txt"]);

http.createServer(async (req, res) => {
  try {
    let p = decodeURIComponent(new URL(req.url, "http://x").pathname);
    if (p === "/mail.php") {
      // atrapa endpointu PHP do lokalnego podglądu (na produkcji obsługuje to serwer)
      res.writeHead(req.method === "POST" ? 200 : 405, { "Content-Type": "application/json" });
      res.end(JSON.stringify({ ok: req.method === "POST" }));
      return;
    }
    if (p.endsWith("/")) p += "index.html";
    const file = path.join(DIST, p);
    if (!file.startsWith(DIST)) throw new Error("path");
    let data;
    try {
      data = await fs.readFile(file);
    } catch {
      res.writeHead(404, { "Content-Type": "text/html; charset=utf-8" });
      res.end(await fs.readFile(path.join(DIST, "404.html")));
      return;
    }
    const ext = path.extname(file);
    const headers = { "Content-Type": MIME[ext] || "application/octet-stream" };
    if (COMPRESS.has(ext) && /gzip/.test(req.headers["accept-encoding"] || "")) {
      headers["Content-Encoding"] = "gzip";
      data = zlib.gzipSync(data);
    }
    res.writeHead(200, headers);
    res.end(data);
  } catch {
    res.writeHead(500);
    res.end();
  }
}).listen(PORT, () => console.log("http://localhost:" + PORT));
