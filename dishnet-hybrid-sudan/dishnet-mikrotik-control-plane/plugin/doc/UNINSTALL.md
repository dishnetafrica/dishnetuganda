# Uninstalling the DishNet MikroTik control plane

## What this destroys

**Everything this plugin stores.** Routers, sites, customers, vouchers,
sessions, telemetry and the entire audit history. There is no backup step here
and nothing is recoverable afterwards.

Take a dump first if any of it matters:

```sh
pg_dump -U dnb -d dnb -Fc -f dnb-before-uninstall.dump
```

## Run it

```sh
set -a; . /etc/dnb/dnb.env; set +a
php plugin/bin/plugin.php uninstall --i-understand-this-drops-data
```

Without that flag it refuses and explains why.

## What it removes

- every `mt_*` table in the `public` schema
- every `mt_*` function in the `public` schema
- the twelve roles the migrations create — six login, six definer — including
  their schema-level grants and default privileges

Grants are revoked before the roles are dropped. A role holding a grant cannot
be dropped, and PostgreSQL reports that as a dependency error naming nothing
useful, so the order is load-bearing rather than tidy.

## What it deliberately leaves

| | Why |
|---|---|
| the database | the plugin did not create it, and something else may live there |
| the owner role | `bootstrap.sql` created it; dropping it would remove the identity you administer with |
| `/etc/dnb/` and any secrets file | outside the database, and yours to remove deliberately |
| the unpacked package directory | same |

## Verify

The command verifies itself and prints what it found — it asks the catalogue
afterwards rather than reporting what it attempted. Expect:

```
verified: no plugin schema remains
verified: no plugin role remains on the cluster
```

Anything else is named explicitly, including any role it could not drop and why.

Check by hand if you prefer:

```sh
psql -U dnb -d dnb -c "SELECT count(*) FROM pg_tables
                        WHERE schemaname='public' AND tablename LIKE 'mt\_%'"
psql -U dnb -d postgres -c "SELECT rolname FROM pg_roles WHERE rolname LIKE 'dnb\_%'"
```

The first must be `0`. The second must list only your owner role.

## Removing the rest

```sh
psql -U postgres -d postgres -c 'DROP DATABASE dnb'
psql -U postgres -d postgres -c 'DROP ROLE dnb'
rm -rf /opt/dishnet-mikrotik-<version> /etc/dnb
```

## On a shared cluster

If you installed onto a cluster that serves anything else, the twelve roles are
**cluster-wide**. Dropping them affects every database on that cluster that
references them. Check before you run the uninstall, not after.
