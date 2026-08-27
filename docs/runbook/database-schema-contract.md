# Database schema contract

`AUD-DATA-003` makes Doctrine migrations the only mutating owner of the
database schema. Neither provisioning, deployment nor a development reset may
run `doctrine:schema:update --force` or `audit:schema:update --force`.

## Read-only gate

Run after migrations and before releasing new application containers:

```bash
php bin/console pim:db:schema:validate --no-interaction
```

The command fails unless all four contracts hold:

1. every configured migration is executed;
2. `audit:schema:update --dump-sql` has nothing to propose;
3. every public table has exactly one owner: current ORM metadata, the 15
   active DH Auditor tables, or the explicit migrations-only allowlist;
4. the sorted `doctrine:schema:update --dump-sql` fingerprint matches the
   reviewed allowlist in `SchemaContractCommand`.

The Doctrine allowlist is deliberately a fingerprint of existing debt, not an
instruction to apply it. When it changes, inspect every printed statement.
Never make the validator green by running the proposed SQL with `--force`.

## Controlled drift probe

Only on an isolated development/test database:

```sql
CREATE TABLE stab_schema_drift_probe (id integer PRIMARY KEY);
```

The validator must exit non-zero and name the table as unowned. Drop only that
explicit probe table afterwards and verify the command returns zero again.

## Fresh database proof

Create an explicitly named disposable database, point both `DATABASE_URL` and
`DATABASE_URL_OWNER` at it, then run:

```bash
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console pim:db:schema:validate --no-interaction
```

The migration command creates all 15 active audit tables. A separate auditor
schema update is neither required nor permitted.

Compare fresh installs with a normalized `pg_dump --schema-only` fingerprint.
For a dump/restore exercise, the read-only contract is the authoritative
comparison: PostgreSQL may re-render equivalent CHECK expressions and partial
index predicates with different casts after restore, so raw dump text is not
guaranteed to be byte-identical.

## Rollback

The takeover migration is intentionally irreversible: databases created by
the old reset path may already have contained the three formerly missing audit
tables, so `down()` cannot know which data it would be safe to delete. Use the
pre-deploy dump and restore it into an explicitly named copy. Validate the copy
before any decision to replace an environment. Production deployment remains
protected by the mandatory pre-deploy dump in `pim-deploy-all.sh`.
