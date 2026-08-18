# Targety instancji tenantów

Pliki `pim-<kod>.yml` w tym katalogu są **generowane**, nie pisane ręcznie:

- dokłada je `scripts/pim-tenant-new.sh` przy zakładaniu instancji (#2861),
- usuwa `scripts/pim-tenant-remove.sh` przy jej usuwaniu (#2862).

Prometheus czyta je przez `file_sd_configs` i przeładowuje bez restartu, więc
dołożenie klienta nie przerywa zbierania metryk pozostałych.

Format jednego pliku:

```yaml
- targets: ["pim-acme-api:80"]
  labels:
    tenant: acme
    service: api
    tier: web
```

Ręczna edycja jest dopuszczalna wyłącznie do diagnostyki — przy następnym
provisioningu plik zostanie nadpisany.
