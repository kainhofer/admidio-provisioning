<?php
/**
 * A small SCIM 2.0 service for tests and for trying the plugin out, as router script of PHP's
 * built-in web server:
 *
 *     SCIM_MOCK_STORE=/tmp/scim.json SCIM_MOCK_TOKEN=secret php -S 127.0.0.1:8089 scim-mock-server.php
 *
 * Base URL: http://127.0.0.1:8089/scim/v2 (allow private networks in the plugin settings). It keeps
 * users and groups in a JSON file and implements what the connector uses: ServiceProviderConfig,
 * filtering by "attribute eq value", POST, PUT, PATCH (replace with a value object, add and remove
 * of members) and DELETE. It is not a conformant SCIM server and must never be exposed.
 */

$store = getenv('SCIM_MOCK_STORE') ?: sys_get_temp_dir() . '/admidio-scim-mock.json';
$token = getenv('SCIM_MOCK_TOKEN') ?: 'test-token';

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = preg_replace('#^/scim/v2#', '', $path);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function respond(int $status, ?array $body = null): void
{
    http_response_code($status);
    header('Content-Type: application/scim+json');
    if ($body !== null) {
        echo json_encode($body, JSON_UNESCAPED_SLASHES);
    }
    exit;
}

function error(int $status, string $detail, string $scimType = ''): void
{
    respond($status, array_filter(array(
        'schemas' => array('urn:ietf:params:scim:api:messages:2.0:Error'),
        'status' => (string) $status,
        'scimType' => $scimType,
        'detail' => $detail
    )));
}

if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer ' . $token) {
    error(401, 'Invalid credentials');
}

$data = is_file($store) ? json_decode((string) file_get_contents($store), true) : null;
$data = is_array($data) ? $data : array('Users' => array(), 'Groups' => array(), 'sequence' => 0);
$save = static function () use (&$data, $store): void {
    file_put_contents($store, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
};
$input = json_decode((string) file_get_contents('php://input'), true);

if ($path === '/ServiceProviderConfig') {
    respond(200, array(
        'schemas' => array('urn:ietf:params:scim:schemas:core:2.0:ServiceProviderConfig'),
        'patch' => array('supported' => true),
        'filter' => array('supported' => true, 'maxResults' => 200),
        'bulk' => array('supported' => false),
        'authenticationSchemes' => array(array('type' => 'oauthbearertoken', 'name' => 'Bearer token'))
    ));
}

if (!preg_match('#^/(Users|Groups)(?:/([^/]+))?$#', $path, $matches)) {
    error(404, 'Unknown endpoint');
}
$type = $matches[1];
$id = isset($matches[2]) ? rawurldecode($matches[2]) : null;

$present = static function (array $resource) use ($type): array {
    $resource['meta'] = array('resourceType' => $type === 'Users' ? 'User' : 'Group');
    if (isset($_GET['excludedAttributes']) && $_GET['excludedAttributes'] === 'members') {
        unset($resource['members']);
    }
    return $resource;
};

if ($id === null && $method === 'GET') {
    $resources = array_values($data[$type]);
    if (isset($_GET['filter']) && preg_match('/^(\w+) eq "(.*)"$/', (string) $_GET['filter'], $filter)) {
        $value = json_decode('"' . $filter[2] . '"');
        $resources = array_values(array_filter($resources, static fn(array $r): bool => (string) ($r[$filter[1]] ?? '') === $value));
    }
    respond(200, array(
        'schemas' => array('urn:ietf:params:scim:api:messages:2.0:ListResponse'),
        'totalResults' => count($resources),
        'Resources' => array_map($present, $resources)
    ));
}

if ($id === null && $method === 'POST') {
    $unique = $type === 'Users' ? 'userName' : 'displayName';
    foreach ($data[$type] as $existing) {
        if (($existing[$unique] ?? null) === ($input[$unique] ?? null)) {
            error(409, $unique . ' is already taken', 'uniqueness');
        }
    }
    $input['id'] = ($type === 'Users' ? 'u' : 'g') . ++$data['sequence'];
    $input['members'] = $type === 'Groups' ? ($input['members'] ?? array()) : null;
    $input = array_filter($input, static fn($value): bool => $value !== null);
    $data[$type][$input['id']] = $input;
    $save();
    respond(201, $present($input));
}

if ($id === null || !isset($data[$type][$id])) {
    error(404, 'Resource ' . $id . ' not found');
}
$resource = $data[$type][$id];

switch ($method) {
    case 'GET':
        respond(200, $present($resource));

    case 'PUT':
        $input['id'] = $id;
        $data[$type][$id] = $input;
        $save();
        respond(200, $present($input));

    case 'DELETE':
        unset($data[$type][$id]);
        if ($type === 'Users') {
            foreach ($data['Groups'] as &$group) {
                $group['members'] = array_values(array_filter($group['members'] ?? array(), static fn(array $m): bool => $m['value'] !== $id));
            }
        }
        $save();
        respond(204);

    case 'PATCH':
        foreach ((array) ($input['Operations'] ?? array()) as $operation) {
            $op = strtolower((string) ($operation['op'] ?? ''));
            $opPath = (string) ($operation['path'] ?? '');
            if ($op === 'replace' && $opPath === '') {
                foreach ((array) $operation['value'] as $key => $value) {
                    $resource[$key] = $value;
                }
            } elseif ($op === 'add' && $opPath === 'members') {
                $known = array_column($resource['members'] ?? array(), 'value');
                foreach ((array) $operation['value'] as $member) {
                    if (!in_array($member['value'], $known, true)) {
                        $resource['members'][] = array('value' => $member['value']);
                    }
                }
            } elseif ($op === 'remove' && preg_match('/^members\[value eq "(.*)"\]$/', $opPath, $member)) {
                $resource['members'] = array_values(array_filter(
                    $resource['members'] ?? array(),
                    static fn(array $m): bool => $m['value'] !== $member[1]
                ));
            } else {
                error(400, 'Unsupported operation ' . $op . ' ' . $opPath, 'invalidSyntax');
            }
        }
        $data[$type][$id] = $resource;
        $save();
        respond(200, $present($resource));
}

error(405, 'Method not allowed');
