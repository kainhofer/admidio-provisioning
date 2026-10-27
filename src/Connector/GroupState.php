<?php
namespace AdmidioPlugin\Provisioning\Connector;

/**
 * The desired state of one group on a target.
 */
final class GroupState
{
    /**
     * @param string $key Stable identity of the group (the pvg_uuid). SCIM sends it as externalId.
     * @param string $name Group name on the target.
     * @param array<int,string> $memberKeys Member keys of all members the group should have, in the
     *                                      form the connector uses (remote account ID or email).
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly array $memberKeys = array()
    ) {
    }

    /**
     * Hash of the name and the members. The members are identified by their Admidio user UUIDs,
     * so that the hash does not depend on remote IDs that are only known after provisioning.
     *
     * @param array<int,string> $memberUserUuids
     */
    public static function hashOf(string $name, array $memberUserUuids): string
    {
        sort($memberUserUuids);
        return hash('sha256', json_encode(array($name, array_values($memberUserUuids)), JSON_UNESCAPED_UNICODE));
    }
}
