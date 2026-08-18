#!/usr/bin/env python3
"""Testy walidatora zleceń provisioningu (epik TNT, #2905).

Walidator jest ostatnią rzeczą stojącą między żądaniem HTTP a komponentem
z uprawnieniami roota na hoście. Testowany jest więc przede wszystkim od
strony ODRZUCEŃ — ścieżka sukcesu ma jeden przypadek, ścieżek odmowy jest
kilkanaście.

Uruchamianie: python3 -m unittest discover -s docker/provisioner -p 'test_*.py'
"""

import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))

from provisioner import (  # noqa: E402
    ACTIONS,
    PROTECTED_PROJECTS,
    RESERVED,
    Rejected,
    _json_objects,
    compose,
    validate,
)


def job(**overrides):
    base = {
        "action": "create",
        "code": "acme",
        "subdomain": "acme",
        "owner_email": "owner@acme.pl",
        "owner_password": "dostatecznie-dlugie-haslo",
        "name": "Acme",
        "requested_by": "019f7ba4-af3a-7c52-af45-05cca3313697",
    }
    base.update(overrides)
    return base


class ValidateAcceptsWellFormedJobs(unittest.TestCase):
    def test_minimal_create(self):
        spec = validate(job())
        self.assertEqual("pim-acme", spec["project"])
        self.assertEqual("acme", spec["subdomain"])

    def test_subdomain_defaults_to_code(self):
        spec = validate(job(subdomain=None))
        self.assertEqual("acme", spec["subdomain"])

    def test_subdomain_may_differ_from_code(self):
        spec = validate(job(code="acme-sp-zoo", subdomain="acme"))
        self.assertEqual("pim-acme-sp-zoo", spec["project"])
        self.assertEqual("acme", spec["subdomain"])

    def test_lifecycle_actions_need_no_owner(self):
        for action in ("suspend", "reactivate", "delete", "purge"):
            with self.subTest(action=action):
                spec = validate(job(action=action, owner_email=None,
                                    owner_password=None))
                self.assertEqual(action, spec["action"])


class ValidateRejectsDangerousJobs(unittest.TestCase):
    def assert_rejected(self, **overrides):
        with self.assertRaises(Rejected):
            validate(job(**overrides))

    def test_unknown_action(self):
        self.assert_rejected(action="exec")

    def test_missing_action(self):
        self.assert_rejected(action=None)

    def test_command_injection_in_code(self):
        # Kod trafia do nazwy projektu i do argumentów procesu. Gdyby
        # przeszedł, znaki specjalne byłyby groźne dopiero w połączeniu
        # z powłoką — dlatego provisioner nie używa powłoki ANI nie
        # przepuszcza takich nazw.
        for evil in ["acme; rm -rf /", "acme && whoami", "acme$(id)",
                     "acme`id`", "acme|cat", "../../etc", "acme\nrm"]:
            with self.subTest(code=evil):
                self.assert_rejected(code=evil)

    def test_code_shape(self):
        for bad in ["", "a", "ab", "-acme", "acme-", "ACME", "acme_pl",
                    "acme.pl", "a" * 33, "acme pl"]:
            with self.subTest(code=bad):
                self.assert_rejected(code=bad)

    def test_reserved_names(self):
        for name in sorted(RESERVED):
            with self.subTest(code=name):
                self.assert_rejected(code=name)
                self.assert_rejected(code="klient", subdomain=name)

    def test_protected_projects_are_unreachable(self):
        # `pim` i `pim-platform` to stack współdzielony i platforma.
        # Zlecenie celujące w nie musi paść, choćby przeszło resztę reguł.
        for project in sorted(PROTECTED_PROJECTS):
            code = project[len("pim-"):] if project.startswith("pim-") else project
            with self.subTest(project=project):
                self.assert_rejected(code=code)

    def test_owner_email_must_look_like_one(self):
        for bad in [None, "", "nie-adres", "a" * 300 + "@x.pl", 42]:
            with self.subTest(owner=bad):
                self.assert_rejected(owner_email=bad)

    def test_owner_password_length(self):
        for bad in [None, "", "krotkie", 12345]:
            with self.subTest(password=bad):
                self.assert_rejected(owner_password=bad)

    def test_non_string_code(self):
        for bad in [None, 42, ["acme"], {"code": "acme"}]:
            with self.subTest(code=bad):
                self.assert_rejected(code=bad)


class ComposeCallsAreAlwaysScopedToOneInstance(unittest.TestCase):
    """#2929: Compose bez jawnego projektu i pliku środowiska trafia w stack
    współdzielony. Raz już tak odtworzyliśmy cudzą bazę — kształt wywołania
    jest przypięty testem, a nie tylko komentarzem."""

    def test_project_and_env_file_are_explicit(self):
        argv = compose("acme", "pim-acme", "stop")
        self.assertEqual(
            ["docker", "compose", "-p", "pim-acme",
             "--env-file", ".env.tenant.acme",
             "-f", "docker-compose.tenant.yml", "stop"],
            argv,
        )

    def test_no_argument_is_a_shell_string(self):
        for arg in compose("acme", "pim-acme", "ps", "--format", "json"):
            self.assertNotIn(" ", arg, "argument sklejony ze spacją idzie do powłoki jako jeden token")


class HealthOutputParsing(unittest.TestCase):
    """`docker compose ps --format json` wypisuje raz tablicę, raz obiekt na
    linię — zależnie od wersji Compose'a. Oba kształty muszą działać, inaczej
    wznowienie instancji „nigdy się nie kończy" na nowszym hoście."""

    def test_object_per_line(self):
        raw = '{"Service":"api","State":"running","Health":"healthy"}\n'
        self.assertEqual([{"Service": "api", "State": "running", "Health": "healthy"}],
                         _json_objects(raw))

    def test_json_array(self):
        raw = '[{"Service":"api","State":"running","Health":"starting"}]'
        self.assertEqual("starting", _json_objects(raw)[0]["Health"])

    def test_garbage_is_skipped_not_crashed(self):
        self.assertEqual([], _json_objects("nie-json\n\n"))


class DeleteAndPurgeAreDifferentActions(unittest.TestCase):
    """Kryterium akceptacji #2909: skasowanie w panelu jest odwracalne przez
    30 dni, a wolumeny giną dopiero przy `purge`. Gdyby obie rzeczy robiła
    jedna akcja, pomyłka operatora byłaby nieodwracalna."""

    def test_both_actions_exist_and_are_distinct(self):
        self.assertIn("delete", ACTIONS)
        self.assertIn("purge", ACTIONS)

    def test_matches_php_contract(self):
        php = (Path(__file__).parents[2]
               / "apps/api/src/Identity/Infrastructure/Provisioning/ProvisioningQueue.php")
        if not php.is_file():
            self.skipTest(f"brak {php} — uruchomienie spoza repozytorium")

        import re
        block = re.search(r"LIFECYCLE_ACTIONS = \[(.*?)\];", php.read_text(encoding="utf-8"), re.S)
        self.assertIsNotNone(block, "ProvisioningQueue nie deklaruje LIFECYCLE_ACTIONS")
        from_php = set(re.findall(r"'([a-z]+)'", block.group(1)))
        self.assertEqual(from_php | {"create"}, ACTIONS)


class ReservedListMatchesTheRestOfTheSystem(unittest.TestCase):
    """Kontrakt nazw żyje w trzech miejscach: PHP, skrypt i provisioner.

    Rozjazd oznacza, że panel przyjmie nazwę, której provisioner odmówi —
    albo, gorzej, odwrotnie.
    """

    def test_matches_php_value_object(self):
        php = (Path(__file__).parents[2] / "apps/api/src/Shared/Domain/TenantSubdomain.php")
        if not php.is_file():
            self.skipTest(f"brak {php} — uruchomienie spoza repozytorium")

        import re
        block = re.search(r"RESERVED = \[(.*?)\];", php.read_text(encoding="utf-8"), re.S)
        self.assertIsNotNone(block, "TenantSubdomain nie deklaruje RESERVED")
        from_php = set(re.findall(r"'([a-z0-9-]+)'", block.group(1)))
        self.assertEqual(from_php, RESERVED)

    def test_matches_provisioning_script(self):
        script = Path(__file__).parents[2] / "scripts/pim-tenant-env.sh"
        if not script.is_file():
            self.skipTest(f"brak {script} — uruchomienie spoza repozytorium")

        import re
        block = re.search(r'RESERVED_SUBDOMAINS="([^"]+)"', script.read_text(encoding="utf-8"))
        self.assertIsNotNone(block, "skrypt nie deklaruje RESERVED_SUBDOMAINS")
        self.assertEqual(set(block.group(1).split()), RESERVED)


if __name__ == "__main__":
    unittest.main(verbosity=2)
