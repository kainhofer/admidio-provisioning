<?php
namespace AdmidioPlugin\Provisioning\Connector;

/**
 * The desired state of one account on a target, as the planner calculated it from Admidio.
 *
 * The attributes are keyed by the attribute paths of the connector (for SCIM for example
 * **name.givenName** or **emails**). Empty values are left out, so an account whose mapped field is
 * empty and one where the field is not mapped look the same.
 */
final class Account
{
    /**
     * @param string $key Stable identity, the usr_uuid of the user. SCIM sends it as externalId.
     * @param string $name Account name on the target (SCIM userName).
     * @param string $email Primary email address, used by connectors whose members are addresses.
     * @param bool $active Whether the account should be active.
     * @param array<string,string> $attributes Mapped attributes by connector path.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $email,
        public readonly bool $active,
        public readonly array $attributes = array()
    ) {
    }

    /**
     * A copy of this account with another active state, for deactivation.
     */
    public function withActive(bool $active): self
    {
        return new self($this->key, $this->name, $this->email, $active, $this->attributes);
    }

    /**
     * Hash of everything that is sent to the target. Two accounts with the same hash need no update.
     */
    public function hash(): string
    {
        $attributes = $this->attributes;
        ksort($attributes);

        return hash('sha256', json_encode(
            array($this->name, $this->email, $this->active, $attributes),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
    }
}
