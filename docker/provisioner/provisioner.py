#!/usr/bin/env python3
"""Provisioner instancji tenantów (epik TNT, #2905, ADR-0036).

Jedyny komponent z dostępem do demona Dockera. Wykonuje zlecenia zapisane
przez instancję platformową w katalogu kolejki i raportuje postęp z powrotem
do pliku statusu, który panel pokazuje operatorowi (#2907).

Dlaczego katalog, a nie HTTP: zlecenie przekazane plikiem nie wymaga, żeby
komponent z uprawnieniami roota na hoście nasłuchiwał na jakimkolwiek porcie.
Powierzchnia sieciowa tej usługi wynosi zero.

Wymagania wynikają wprost z czterech defektów złapanych przy budowie M0–M2
(#2922, #2929) — każdy z nich byłby w tym miejscu incydentem, a nie
niedogodnością:

  * jawny projekt i plik środowiska przy każdym wywołaniu Dockera,
  * parametry przekazywane wyłącznie argumentami, nigdy przez środowisko,
  * uruchamianie procesów tablicą argumentów, nigdy przez powłokę,
  * allowlista projektów sprawdzana PRZED wykonaniem,
  * ścieżki porażki obsłużone tak samo starannie jak ścieżka sukcesu.
"""

from __future__ import annotations

import json
import os
import re
import subprocess
import sys
import time
import uuid
from datetime import datetime, timezone
from pathlib import Path

SPOOL = Path(os.environ.get("PROVISIONER_SPOOL", "/spool"))
WORKSPACE = Path(os.environ.get("PROVISIONER_WORKSPACE", "/workspace"))
SHARED_ENV = os.environ.get("PROVISIONER_SHARED_ENV", ".env.prod")
POLL_SECONDS = float(os.environ.get("PROVISIONER_POLL_SECONDS", "3"))
STEP_TIMEOUT = int(os.environ.get("PROVISIONER_STEP_TIMEOUT", "1800"))

# Kontrakt nazw MUSI być identyczny z App\Shared\Domain\TenantSubdomain
# i ze scripts/pim-tenant-env.sh. Rozjazd oznacza, że panel przyjmie nazwę,
# której provisioner odmówi — albo, gorzej, odwrotnie.
CODE_PATTERN = re.compile(r"^[a-z0-9][a-z0-9-]{1,30}[a-z0-9]$")
RESERVED = {
    "admin", "api", "app", "docs", "mail", "meili", "mercure", "minio",
    "pim", "platform", "staging", "status", "test", "www",
}

# Projekty, których provisioner NIE MOŻE dotknąć, choćby zlecenie tak mówiło:
# stack współdzielony i instancja platformowa. To jest ostatnia zapora przed
# zleceniem, które przeszło walidację, a mimo to celuje w cudzy stack.
PROTECTED_PROJECTS = {"pim", "pim-platform"}

ACTIONS = {"create", "suspend", "reactivate", "delete"}


def now() -> str:
    return datetime.now(timezone.utc).isoformat()


def audit(event: str, **fields) -> None:
    """Log audytowy: jedna linia JSON na zdarzenie, na stdout.

    Trafia do `docker logs`, więc zostaje nawet wtedy, gdy wolumen kolejki
    zostanie wyczyszczony. Każde zlecenie ma tu ślad razem z operatorem,
    który je zlecił.
    """
    record = {"ts": now(), "event": event, **fields}
    print(json.dumps(record, ensure_ascii=False), flush=True)


class Rejected(Exception):
    """Zlecenie odrzucone przed wykonaniem czegokolwiek."""


def validate(job: dict) -> dict:
    """Sprawdza zlecenie ZANIM cokolwiek zostanie uruchomione.

    Walidacja jest tu powtórzona mimo tego, że robi ją też API. To nie jest
    nadmiarowość: API i provisioner są osobnymi komponentami, a ten drugi ma
    uprawnienia roota na hoście. Nie ufa wołającemu.
    """
    action = job.get("action")
    if action not in ACTIONS:
        raise Rejected(f"nieznana akcja: {action!r}")

    code = job.get("code")
    if not isinstance(code, str) or not CODE_PATTERN.match(code):
        raise Rejected(f"kod tenanta nie spelnia kontraktu: {code!r}")
    if code in RESERVED:
        raise Rejected(f"kod tenanta jest zastrzezony: {code!r}")

    subdomain = job.get("subdomain") or code
    if not isinstance(subdomain, str) or not CODE_PATTERN.match(subdomain):
        raise Rejected(f"subdomena nie spelnia kontraktu: {subdomain!r}")
    if subdomain in RESERVED:
        raise Rejected(f"subdomena jest zastrzezona: {subdomain!r}")

    project = f"pim-{code}"
    if project in PROTECTED_PROJECTS:
        raise Rejected(f"projekt chroniony, odmowa: {project}")

    if action == "create":
        owner = job.get("owner_email")
        if not isinstance(owner, str) or "@" not in owner or len(owner) > 254:
            raise Rejected(f"adres wlasciciela nie wyglada na adres: {owner!r}")
        password = job.get("owner_password")
        if not isinstance(password, str) or len(password) < 12:
            raise Rejected("haslo wlasciciela musi miec min. 12 znakow")

    return {
        "action": action,
        "code": code,
        "subdomain": subdomain,
        "project": project,
        "owner_email": job.get("owner_email"),
        "owner_password": job.get("owner_password"),
        "name": job.get("name") or code,
        "requested_by": job.get("requested_by"),
    }


def write_status(job_id: str, **fields) -> None:
    """Zapis atomowy: panel czyta ten plik równolegle z zapisem."""
    path = SPOOL / f"{job_id}.status.json"
    tmp = SPOOL / f".{job_id}.status.{uuid.uuid4().hex}.tmp"
    tmp.write_text(json.dumps({"job_id": job_id, "updated_at": now(), **fields},
                              ensure_ascii=False, indent=2), encoding="utf-8")
    tmp.replace(path)


def run_step(job_id: str, name: str, argv: list[str], env: dict | None = None) -> tuple[int, str]:
    """Uruchamia proces TABLICĄ argumentów, nigdy przez powłokę.

    `shell=False` jest tu decyzją bezpieczeństwa, nie stylem: kod tenanta
    pochodzi z żądania HTTP, a powłoka interpretowałaby w nim znaki specjalne.
    """
    audit("step_start", job_id=job_id, step=name, argv=argv)
    try:
        completed = subprocess.run(
            argv,
            cwd=str(WORKSPACE),
            env={**os.environ, **(env or {})},
            capture_output=True,
            text=True,
            timeout=STEP_TIMEOUT,
            shell=False,
        )
    except subprocess.TimeoutExpired:
        audit("step_timeout", job_id=job_id, step=name, seconds=STEP_TIMEOUT)
        return 124, f"krok '{name}' przekroczyl limit {STEP_TIMEOUT}s"

    output = (completed.stdout or "") + (completed.stderr or "")
    audit("step_end", job_id=job_id, step=name, exit_code=completed.returncode)
    return completed.returncode, output


def handle(job_id: str, job: dict) -> None:
    spec = validate(job)
    action, code, project = spec["action"], spec["code"], spec["project"]

    audit("job_accepted", job_id=job_id, action=action, code=code,
          requested_by=spec["requested_by"])
    write_status(job_id, state="running", action=action, code=code, steps=[])

    steps: list[dict] = []

    def record(step: str, ok: bool, detail: str = "") -> None:
        steps.append({"step": step, "ok": ok, "detail": detail[-2000:]})
        write_status(job_id, state="running", action=action, code=code, steps=steps)

    if action == "create":
        # Hasło idzie zmienną środowiskową POJEDYNCZEGO wywołania, nie
        # argumentem: argumenty są widoczne w liście procesów całego hosta.
        rc, out = run_step(
            job_id, "provision",
            ["bash", "scripts/pim-tenant-new.sh",
             "--code", code,
             "--name", spec["name"],
             "--subdomain", spec["subdomain"],
             "--owner-email", spec["owner_email"],
             "--owner-password-env", "PROVISIONER_OWNER_PASSWORD",
             "--shared-env", SHARED_ENV],
            env={"PROVISIONER_OWNER_PASSWORD": spec["owner_password"]},
        )
        record("provision", rc == 0, out)
    elif action == "delete":
        rc, out = run_step(
            job_id, "deprovision",
            ["bash", "scripts/pim-tenant-remove.sh",
             "--code", code, "--confirm", code],
        )
        record("deprovision", rc == 0, out)
    else:
        compose_action = "stop" if action == "suspend" else "start"
        rc, out = run_step(
            job_id, compose_action,
            ["docker", "compose", "-p", project,
             "--env-file", f".env.tenant.{code}",
             "-f", "docker-compose.tenant.yml", compose_action],
        )
        record(compose_action, rc == 0, out)

    state = "done" if rc == 0 else "failed"
    write_status(job_id, state=state, action=action, code=code, steps=steps,
                 exit_code=rc)
    audit("job_finished", job_id=job_id, action=action, code=code, state=state,
          exit_code=rc)


def claim(path: Path) -> Path | None:
    """Przejmuje zlecenie atomowo, żeby dwa procesy nie wzięły tego samego."""
    claimed = path.with_suffix(".claimed")
    try:
        path.rename(claimed)
    except OSError:
        return None
    return claimed


def process_once() -> int:
    handled = 0
    for path in sorted(SPOOL.glob("*.job.json")):
        claimed = claim(path)
        if claimed is None:
            continue

        job_id = path.name[: -len(".job.json")]
        try:
            job = json.loads(claimed.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError) as exc:
            audit("job_unreadable", job_id=job_id, error=str(exc))
            write_status(job_id, state="failed", error="zlecenie nieczytelne")
            handled += 1
            continue

        try:
            handle(job_id, job)
        except Rejected as exc:
            # Odrzucenie NIE jest awarią provisionera — to jego zadanie.
            # Ważne, żeby zostawiło ślad i nie uruchomiło niczego.
            audit("job_rejected", job_id=job_id, reason=str(exc))
            write_status(job_id, state="rejected", error=str(exc))
        except Exception as exc:  # noqa: BLE001 - ostatnia zapora
            audit("job_crashed", job_id=job_id, error=repr(exc))
            write_status(job_id, state="failed", error=repr(exc))
        handled += 1
    return handled


def main() -> int:
    SPOOL.mkdir(parents=True, exist_ok=True)
    once = "--once" in sys.argv

    audit("provisioner_started", spool=str(SPOOL), workspace=str(WORKSPACE),
          once=once)

    if once:
        process_once()
        return 0

    while True:
        try:
            process_once()
        except Exception as exc:  # noqa: BLE001 - pętla nie może umrzeć
            audit("loop_error", error=repr(exc))
        time.sleep(POLL_SECONDS)


if __name__ == "__main__":
    sys.exit(main())
