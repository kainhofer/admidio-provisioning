# Admidio Provisioning Plugin — keep accounts and groups of other systems in line with Admidio

A third-party plugin for Admidio 6.0 and later. It creates, updates, deactivates and deletes user
accounts and groups on external systems according to Admidio's members and roles. The first connector
speaks **SCIM 2.0**, the standard protocol for user provisioning; the connector model is open for
others (Mailman 3, the Nextcloud provisioning API, Microsoft Graph).

## Installation

As administrator, go to Admidio's plugin manager, select "Add plugin", upload the plugin ZIP file and
enable the plugin. The ZIP is built from a checkout of this repository in `plugins/provisioning` of an
Admidio installation:

```
php admidio plugin:archive provisioning
```

The tables are created when the plugin is installed (`db_scripts/install.sql`). Removing the plugin
deletes them, including the record of which remote accounts belong to which Admidio user — disconnect
or clean up the targets first.

### Cron

Changes in Admidio reach the targets within seconds, at the end of the request that made them.
Membership start and end dates, which Admidio only knows as whole days, take effect with the daily
reconciliation shortly after midnight. Run the plugin hourly, one line per organization:

```
15 * * * *  php /path/to/admidio/admidio provisioning:sync --organization=SHORTNAME --no-interaction --quiet
```

The command needs an acting organization administrator, for example through `$gCliDefaults` in
`adm_my_files/cli-config.php`. Without cron the first page request after midnight starts the
reconciliation; ended memberships then stay active on the target until somebody opens Admidio.

## Configuration

The menu entry *Provisioning* (below *Extensions*, visible to administrators) lists the targets. A
target has

* a **connection** (for SCIM: base URL, bearer token, OAuth 2.0 client credentials or HTTP Basic);
* its **users**: every member of the organization, the members of selected roles, or — if the target
  is **linked to an SSO client** — everybody who may sign in to that client;
* the **account name** (SCIM `userName`) and the **attributes** taken from profile fields;
* **groups**: configured on the target (members or leaders of roles), taken from the role mapping of
  the linked SSO client, and optionally one group for every other role;
* a **verification** schedule that compares the target system with Admidio.

The status page of a target shows its queue, the last runs and every account or group that needs
attention, and offers *Synchronize now*, *Verify now*, *Test connection* and *Retry*.

Members of a group always get an account. Users without a value for the account name are not
provisioned. A user who no longer belongs to a target is removed from its groups and deactivated;
only a user deleted in Admidio is deleted on the target. Accounts and groups the plugin did not create
or take over (by `externalId`) are never touched.

## Command line

| Command | Purpose |
|---------|---------|
| `provisioning:sync [--target=UUID] [--full] [--verify] [--budget=S] [--dry-run]` | Run what is due (for cron) |
| `provisioning:status` | Targets, queues and last runs; exit code 3 if a target is paused or items need attention |
| `provisioning:test --target=UUID` | Test the connection |
| `provisioning:queue --target=UUID [--retry] [--clear --yes]` | Items that need attention; retry or clear |

## Writing a connector

A connector extends `AdmidioPlugin\Provisioning\Connector\Connector` and is registered through the
filter `provisioning_connectors`:

```php
Hooks::addFilter('provisioning_connectors', static function (array $connectors): array {
    $connectors['mailman'] = MailmanConnector::class;
    return $connectors;
});
```

It declares its configuration fields, the attributes it can map and its capabilities, and implements
the account and group operations. `tests/Support/MemoryConnector.php` is a complete example.

## Development

Tests run with Admidio's suite, from the Admidio root:

```
vendor/bin/phpunit plugins/provisioning/tests/Unit
vendor/bin/phpunit plugins/provisioning/tests/Integration
```

## License

GNU General Public License v2.0 only, the same as Admidio.
