# Admidio Provisioning Plugin — keep accounts and groups of other systems in line with Admidio

A third-party plugin for Admidio 6.0 and later. It creates, updates, deactivates and deletes user
accounts and groups on external systems according to Admidio's members and roles. It comes with three
connectors:

* **SCIM 2.0**, the standard protocol for user provisioning;
* **Nextcloud**, through its OCS provisioning API: users, their profile data, enabled state and groups;
* **Mailman 3**, through its REST API: groups are mailing lists, their members are subscribed by email
  address.

Further connectors (for example Microsoft Graph) can be added by other plugins.

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
reconciliation shortly after midnight. Run the maintenance jobs of Admidio hourly, one line per
organization; the plugin registers its job `provisioning:sync` there:

```
15 * * * *  php /path/to/admidio/admidio maintenance:run --organization=SHORTNAME --no-interaction --quiet
```

On an Admidio version without maintenance job registration, run `provisioning:sync` instead of
`maintenance:run`.

The command needs an acting organization administrator, for example through `$gCliDefaults` in
`adm_my_files/cli-config.php`. Without cron the first page request after midnight starts the
reconciliation; ended memberships then stay active on the target until somebody opens Admidio.

### Using what Admidio core offers

Where the Admidio core provides them, the plugin uses the maintenance job registry (one cron entry), the
action `request_finished` (work after the response was sent), the action `membership_period_changed`
(targets are updated right after a membership starts or ends by its date), the shared definition of
a valid membership, and the removal warnings of the plugin manager. On a core without them it uses its
own fallbacks.

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

### Connector notes

* **Nextcloud**: the account name is the Nextcloud user ID and cannot change after the account was
  created. An existing user with that ID is taken over. New users get no password; Nextcloud mails
  them a link to set one, so they need an email address. Authenticate with an app password of an
  administrator.
* **Mailman 3**: the account name defaults to the email address, so users without one are not
  subscribed. A group name is the address of the list, or its local part together with the mail
  domain of the target. Lists are created and deleted only if the target allows it; otherwise a
  removed group only loses the subscriptions Admidio made. A changed email address moves the
  subscription.

For SCIM services that deviate from the standard, a target can send complete resources (PUT instead
of PATCH), work without groups, delete accounts instead of deactivating them, and send the OAuth client
credentials in the request body or as HTTP Basic credentials.

### Removing a target

* **Disconnect** removes the target in Admidio. Its accounts and groups on the target system remain.
* **Deprovision** deletes the groups and then the accounts Admidio created on the target system, and
  removes the target when that is done. The deletions go through the queue, so they survive
  interruptions; an item the target refuses keeps the target in the list until it is retried or the
  target is disconnected.

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
| `provisioning:deprovision --target=UUID --yes` | Delete what Admidio created on the target system, then the target |

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

### Trying it out without a SCIM service

`tests/Support/scim-mock-server.php` is a small SCIM service for PHP's built-in web server. It is
what the HTTP tests use, and it is enough to try the plugin in the browser:

```
SCIM_MOCK_STORE=/tmp/scim.json SCIM_MOCK_TOKEN=secret php -S 127.0.0.1:8089 plugins/provisioning/tests/Support/scim-mock-server.php
```

Create a SCIM target with the base URL `http://127.0.0.1:8089/scim/v2` and the bearer token `secret`,
and allow private networks in the plugin settings. `/tmp/scim.json` then shows what was provisioned.
Never expose the mock server.

## License

GNU General Public License v2.0 only, the same as Admidio.
