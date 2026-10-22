-- Runtime role for the application.
--
-- Why this file exists:
--   The default `POSTGRES_USER` created by the Postgres image is a SUPERUSER with
--   BYPASSRLS, which silently disables every Row Level Security policy. RLS is a
--   security requirement of this project (.ai/06-DATA-MODEL.md §4, ADR-013), so the
--   application must NOT connect as a superuser.
--
--   `postgres` (superuser) is the administration role; `luckycare_app` is the role the
--   Laravel app and its tests use. It owns the schema, and every tenant-scoped table is
--   declared with FORCE ROW LEVEL SECURITY so table ownership does not bypass policies.

CREATE ROLE luckycare_app
    LOGIN
    PASSWORD 'luckycare_app_dev'
    NOSUPERUSER
    NOCREATEDB
    NOCREATEROLE
    NOBYPASSRLS;

GRANT ALL PRIVILEGES ON DATABASE lucky_care_plus TO luckycare_app;

\connect lucky_care_plus

ALTER SCHEMA public OWNER TO luckycare_app;

GRANT ALL ON SCHEMA public TO luckycare_app;
ALTER DATABASE lucky_care_plus OWNER TO luckycare_app;
