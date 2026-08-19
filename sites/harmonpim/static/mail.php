<?php
/**
 * Harmon PIM — wysyłka formularzy (modal demo + formularz kontaktowy).
 * Jedyny plik wymagający PHP; reszta strony jest w pełni statyczna.
 */

// ── KONFIGURACJA ──────────────────────────────────────────────────────────
// Zgłoszenia idą PROSTO na skrzynkę odbiorczą operatora. Domena harmonpim.pl
// nie ma (jeszcze) rekordu MX, więc adres kontakt@harmonpim.pl nie istnieje i
// wysyłka na niego odbiłaby się. Wysyłanie Z domeny nie wymaga skrzynki W tej
// domenie — autoryzuje je podpis DKIM Resendu. Gdy pojawi się skrzynka lub
// przekierowanie na harmonpim.pl, wystarczy podmienić tę jedną linię.
$ADRES_DOCELOWY = "marcin.lipiec@gmail.com";
// Nadawca techniczny — bez myślnika, żeby zgadzał się z MAILER_FROM aplikacji
// PIM. Jeden adres nadawcy = jedna reputacja do zbudowania, nie dwie.
$ADRES_NADAWCY  = "noreply@harmonpim.pl";
// ──────────────────────────────────────────────────────────────────────────

header("Content-Type: application/json; charset=utf-8");
header("X-Content-Type-Options: nosniff");

function odpowiedz(int $kod, bool $ok): void {
    http_response_code($kod);
    echo json_encode(["ok" => $ok]);
    exit;
}

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") odpowiedz(405, false);

function pole(string $klucz, int $max): string {
    $w = trim((string)($_POST[$klucz] ?? ""));
    $w = str_replace(["\r", "\n", "%0a", "%0d"], " ", $w); // ochrona przed wstrzyknięciem nagłówków
    return mb_substr($w, 0, $max);
}

// Antyspam: honeypot (wypełni tylko bot) + token dodawany przez site.js przy wysyłce
if (pole("www", 10) !== "" || pole("_t", 20) !== "harmon") odpowiedz(400, false);

$imie    = pole("imie", 120);
$email   = pole("email", 200);
$telefon = pole("telefon", 60);
$firma   = pole("firma", 200);

if ($imie === "" || $firma === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) odpowiedz(400, false);

if ((string)($_POST["formularz"] ?? "") === "kontakt") {
    $temat = "Wiadomość ze strony harmonpim.pl (formularz kontaktowy)";
    $wiadomosc = mb_substr(trim((string)($_POST["wiadomosc"] ?? "")), 0, 5000);
    $tresc = "Imię i nazwisko: $imie\n"
           . "E-mail: $email\n"
           . "Telefon: $telefon\n"
           . "Firma: $firma\n\n"
           . "Wiadomość:\n$wiadomosc\n";
} else {
    $temat = "Zgłoszenie prezentacji Harmon PIM";
    $platforma = pole("platforma", 100);
    $sku = pole("sku", 100);
    $tresc = "Imię: $imie\n"
           . "E-mail: $email\n"
           . "Telefon: $telefon\n"
           . "Firma: $firma\n"
           . "Platforma sklepu: $platforma\n"
           . "Liczba SKU: $sku\n";
}

$tresc .= "\n--\nWysłano " . date("Y-m-d H:i") . " z adresu IP " . ($_SERVER["REMOTE_ADDR"] ?? "?");

$naglowki = "From: Harmon PIM <$ADRES_NADAWCY>\r\n"
          . "Reply-To: $email\r\n"
          . "MIME-Version: 1.0\r\n"
          . "Content-Type: text/plain; charset=UTF-8\r\n"
          . "Content-Transfer-Encoding: 8bit";

$wyslane = @mail($ADRES_DOCELOWY, "=?UTF-8?B?" . base64_encode($temat) . "?=", $tresc, $naglowki);

odpowiedz($wyslane ? 200 : 500, $wyslane);
