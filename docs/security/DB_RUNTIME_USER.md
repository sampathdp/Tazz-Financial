# Least-privilege database accounts (roadmap 5.4)

Goal: the account the web app uses every day cannot change schema, drop tables, read other databases or grant rights, so an
application-level compromise (SQL injection, leaked `.env`) cannot wipe or restructure the database.

Nothing here has been run. It changes the live server; run it yourself, in this order, and keep the old credentials until step 5 passes.

## Accounts

| Account | Used by | Rights |
|---------|---------|--------|
| runtime (`DB_USERNAME`) | every API request | `SELECT, INSERT, UPDATE, DELETE` on the app database |
| migrate (`DB_MIGRATE_USERNAME`) | only `public/opcache-reset.php?action=migrate` during deploy | runtime rights plus `CREATE, ALTER, DROP, INDEX, REFERENCES` on the app database |

The deploy workflow calls the migrate endpoint; the endpoint now switches to the migrate account when `DB_MIGRATE_USERNAME` is set
(`backend/public/opcache-reset.php`, `config/database.php`). If it is not set, behaviour is unchanged.

## Steps

1. Create the two accounts (host `localhost`; use your own strong passwords, never reuse the old one):

```sql
CREATE USER 'abs_app'@'localhost'     IDENTIFIED BY '<new strong password 1>';
CREATE USER 'abs_migrate'@'localhost' IDENTIFIED BY '<new strong password 2>';

GRANT SELECT, INSERT, UPDATE, DELETE ON `<database>`.* TO 'abs_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES
      ON `<database>`.* TO 'abs_migrate'@'localhost';
FLUSH PRIVILEGES;
```

2. In the production `.env` set `DB_USERNAME=abs_app` / `DB_PASSWORD=...` and `DB_MIGRATE_USERNAME=abs_migrate` / `DB_MIGRATE_PASSWORD=...`.
   Clear the config cache (`config:clear`; the deploy already does).
3. Smoke test: log in, open a loan, record and reverse a payment, post a journal entry, upload a document.
4. Deploy a change that contains a migration and confirm the `migrate` step in the workflow reports success.
5. Only then revoke the old broad account, and rotate its password.

## What stops working on purpose

- `POST system/clean-transactions` uses `TRUNCATE`, which needs `DROP`. It is already blocked in production unless `DB_CLEAN_ENABLED`; with
  a DML-only runtime user it can no longer succeed even then. Run a data wipe, if ever needed, from a trusted shell with the migrate account.
- Anything else issuing DDL at request time: none found (`Schema::` is only used for `defaultStringLength` and inside the cleaner).

## Audit tables

Money-ledger history is voided/reversed, not deleted, by the application paths (see FINANCIAL_SECURITY_AUDIT.md). To make that a database
guarantee too, grant only `SELECT, INSERT` per table on append-only tables (for example `finance_audit_events`) and the four DML rights on the rest.
MySQL does not let a table-level `REVOKE` subtract from a database-level grant, so this means granting per table instead of `<database>.*`.
Before doing it, confirm with `grep -rn <table> backend/app` that no code path updates or deletes rows in that table; a missed path would start failing in production.

## Rollback

Point `DB_USERNAME`/`DB_PASSWORD` back at the old account and clear the config cache. The migrate account can stay unused.
