<?php
/**
 * GameCP Provisioning Module for WHMCS
 *
 * Integrates with GameCP to automatically provision game servers
 * when orders are paid in WHMCS.
 *
 * @copyright Copyright (c) 2024
 * @license See LICENSE
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

// ============================================================================
// Module Configuration
// ============================================================================

/**
 * Define module configuration metadata.
 */
function gamecp_MetaData()
{
    return array(
        'DisplayName' => 'GameCP',
        'APIVersion' => '1.1',
        'RequiresServer' => true,
        'DefaultNonSSLPort' => '80',
        'DefaultSSLPort' => '443',
        'ServiceSingleSignOnLabel' => 'Access Control Panel',
        'AdminSingleSignOnLabel' => 'Login to Admin Panel',
        'ListAccountsUniqueIdentifierField' => 'domain',
        'ListAccountsUniqueIdentifierDisplayName' => 'Server Name',
        'Description' => 'Automated game server provisioning and management. Instantly deploy, control, and monitor game servers for your customers with full SSO integration.',
        'Author' => 'GameCP',
        'AuthorURL' => 'https://gamecp.com',
        'Version' => '1.4.0',
        'Category' => 'Game Servers',
        'SupportURL' => 'https://gamecp.com/support',
        'DocumentationURL' => 'https://docs.gamecp.com/whmcs',
    );
}

/**
 * Define module configuration parameters.
 *
 * Config Options:
 *   1: Game Config ID
 *   2: Node ID
 *   3: Location
 *   4: Auto Deploy (yes/no)
 *   5: Server Name Format
 */
function gamecp_ConfigOptions()
{
    return array(
        'Game Config ID' => array(
            'Type' => 'text',
            'Size' => '50',
            'Description' => 'The Game Config ID to use'
        ),
        'Node ID' => array(
            'Type' => 'text',
            'Size' => '50',
            'Description' => 'The Node ID to use (leave empty for auto-assignment)'
        ),
        'Location' => array(
            'Type' => 'text',
            'Size' => '50',
            'Description' => 'Target location for deployment (optional)'
        ),
        'Auto Deploy' => array(
            'Type' => 'yesno',
            'Description' => 'Automatically deploy/install the game server after creation.'
        ),
        'Server Name Format' => array(
            'Type' => 'text',
            'Size' => '80',
            'Default' => '{product}',
            'Description' => 'Name format for created servers. Variables: {product}, {serviceid}, {domain}, {clientname}'
        ),
    );
}

// ============================================================================
// Shared Helpers
// ============================================================================

/**
 * Resolve API credentials from WHMCS params.
 *
 * Handles the WHMCS Server Group workaround where credentials may not be
 * directly available in $params. Tries: direct params → serverid lookup →
 * product server group lookup.
 *
 * @param array $params WHMCS module parameters
 * @return array ['apiUrl' => string, 'apiKey' => string]
 */
function gamecp_ResolveCredentials(array $params)
{
    $apiUrl = $params['serverhostname'] ?: ($params['serverip'] ?? '');
    $apiKey = $params['serveraccesshash'] ?? '';

    // If we already have credentials, just ensure URL protocol and return
    if (!empty($apiKey)) {
        return gamecp_NormalizeCredentials($apiUrl, $apiKey);
    }

    // Try direct serverid lookup
    if (!empty($params['serverid']) && $params['serverid'] > 0) {
        try {
            $serverData = \WHMCS\Database\Capsule::table('tblservers')
                ->where('id', $params['serverid'])
                ->first();
            if ($serverData && !empty($serverData->accesshash)) {
                return gamecp_NormalizeCredentials(
                    $serverData->hostname ?: $serverData->ipaddress,
                    $serverData->accesshash
                );
            }
        } catch (\Exception $e) {
            // Continue to next fallback
        }
    }

    // Fallback: product's server group
    if (!empty($params['pid'])) {
        try {
            $product = \WHMCS\Database\Capsule::table('tblproducts')
                ->where('id', $params['pid'])
                ->first();
            if ($product && $product->servergroup) {
                $serverData = \WHMCS\Database\Capsule::table('tblservers')
                    ->join('tblservergroupsrel', 'tblservers.id', '=', 'tblservergroupsrel.serverid')
                    ->where('tblservergroupsrel.groupid', $product->servergroup)
                    ->where('tblservers.type', 'gamecp')
                    ->first();
                if ($serverData && !empty($serverData->accesshash)) {
                    return gamecp_NormalizeCredentials(
                        $serverData->hostname ?: $serverData->ipaddress,
                        $serverData->accesshash
                    );
                }
            }
        } catch (\Exception $e) {
            // Continue with what we have
        }
    }

    return gamecp_NormalizeCredentials($apiUrl, $apiKey);
}

/**
 * Normalize API credentials (ensure URL has protocol).
 */
function gamecp_NormalizeCredentials($apiUrl, $apiKey)
{
    if (!empty($apiUrl) && strpos($apiUrl, 'http') === false) {
        $apiUrl = "https://" . $apiUrl;
    }
    return array('apiUrl' => $apiUrl, 'apiKey' => $apiKey);
}

/**
 * Get the GameCP server ID from WHMCS service params.
 *
 * Checks: assignedips (current) → dedicatedip (legacy) → custom fields (fallback)
 *
 * @param array $params WHMCS module parameters
 * @return string Server ID or empty string
 */
function gamecp_GetServerId(array $params)
{
    return $params['model']->assignedips
        ?: $params['model']->dedicatedip
        ?: ($params['customfields']['GameCP Server ID'] ?? '');
}

/**
 * Resolve product name from WHMCS.
 *
 * @param array $params WHMCS module parameters
 * @return string Product name
 */
function gamecp_GetProductName(array $params)
{
    if (!empty($params['pid'])) {
        try {
            $product = \WHMCS\Database\Capsule::table('tblproducts')
                ->where('id', $params['pid'])
                ->first();
            if ($product && !empty($product->name)) {
                return $product->name;
            }
        } catch (Exception $e) {
            // Fallback
        }
    }
    return 'Game Server';
}

/**
 * Generate server name from configurable format.
 *
 * @param array $params WHMCS module parameters
 * @return string Resolved server name
 */
function gamecp_GenerateServerName(array $params)
{
    $nameFormat = trim($params['configoption5'] ?? '') ?: '{product}';
    $domain = $params['domain'] ?? '';
    $serviceId = $params['serviceid'] ?? '';
    $clientId = $params['clientsdetails']['userid'] ?? '';

    // Filter out WHMCS auto-generated hostnames like "server-1-1769213895"
    $cleanDomain = (!empty($domain) && !preg_match('/^server-\d+-\d+$/', $domain)) ? $domain : '';

    $serverName = str_replace(
        array('{product}', '{serviceid}', '{domain}', '{clientname}'),
        array(
            gamecp_GetProductName($params),
            $serviceId,
            $cleanDomain,
            trim(($params['clientsdetails']['firstname'] ?? '') . ' ' . ($params['clientsdetails']['lastname'] ?? '')),
        ),
        $nameFormat
    );

    $serverName = trim($serverName);
    return !empty($serverName) ? $serverName : 'Game Server #' . ($serviceId ?: $clientId);
}

// ============================================================================
// Connection Test
// ============================================================================

/**
 * Test connection to GameCP server.
 */
function gamecp_TestConnection(array $params)
{
    try {
        $creds = gamecp_ResolveCredentials($params);
        $apiUrl = $creds['apiUrl'];
        $apiKey = $creds['apiKey'];

        if (empty($apiUrl)) {
            return array('success' => false, 'error' => 'Hostname or IP Address is required');
        }
        if (empty($apiKey)) {
            return array('success' => false, 'error' => 'API Key (Access Hash) is required');
        }

        $testUrl = rtrim($apiUrl, '/') . '/api/users?limit=1';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $testUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        logModuleCall('gamecp', 'TestConnection', array(
            'url' => $testUrl,
            'has_api_key' => !empty($apiKey),
        ), $response, array('http_code' => $httpCode, 'curl_error' => $curlError), array());

        if ($curlError) {
            return array('success' => false, 'error' => 'Connection error: ' . $curlError);
        }
        if ($httpCode === 0) {
            return array('success' => false, 'error' => 'Could not connect to ' . $apiUrl);
        }
        if ($httpCode === 401) {
            return array('success' => false, 'error' => 'Authentication failed. Check your API Key.');
        }
        if ($httpCode === 404) {
            return array('success' => false, 'error' => 'API endpoint not found. Verify your hostname.');
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            return array('success' => false, 'error' => 'API returned HTTP ' . $httpCode);
        }

        $data = json_decode($response, true);
        if (!$data) {
            return array('success' => false, 'error' => 'Received invalid JSON response.');
        }
        if (!isset($data['users']) && !isset($data['data'])) {
            return array('success' => false, 'error' => 'Unexpected response structure.');
        }

        return array('success' => true, 'error' => '');

    } catch (Exception $e) {
        logModuleCall('gamecp', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString(), array());
        return array('success' => false, 'error' => 'Connection test failed: ' . $e->getMessage());
    }
}

// ============================================================================
// Provisioning Actions
// ============================================================================

/**
 * Create game server when order is activated.
 */
function gamecp_CreateAccount(array $params)
{
    try {
        $creds = gamecp_ResolveCredentials($params);
        $apiUrl = $creds['apiUrl'];
        $apiKey = $creds['apiKey'];

        // Config options
        $gameConfigId = trim($params['configoption1']);
        $nodeId = trim($params['configoption2']);
        $location = trim($params['configoption3']);

        // Client info
        $clientEmail = $params['clientsdetails']['email'];
        $clientFirstName = $params['clientsdetails']['firstname'];
        $clientLastName = $params['clientsdetails']['lastname'];

        // Server name
        $serverName = gamecp_GenerateServerName($params);

        logModuleCall('gamecp', __FUNCTION__, array(
            'email' => $clientEmail,
            'serverName' => $serverName,
            'gameConfigId' => $gameConfigId,
        ), '', array(), array());

        // Step 1: Ensure user exists in GameCP
        $userId = gamecp_EnsureUserExists($apiUrl, $apiKey, array(
            'email' => $clientEmail,
            'firstName' => $clientFirstName,
            'lastName' => $clientLastName,
            'role' => 'user',
            'password' => $params['password']
        ));

        // Set WHMCS username to email for consistency
        try {
            \WHMCS\Database\Capsule::table('tblhosting')
                ->where('id', $params['serviceid'])
                ->update(['username' => $clientEmail]);
        } catch (Exception $e) {
            // Cosmetic, ignore
        }

        if (!$userId) {
            return 'error: Could not find or create user in GameCP';
        }

        // Step 2: Validate game config
        if (empty($gameConfigId)) {
            $gameConfigId = $params['customfields']['Game Config ID'] ?? '';
            if (empty($gameConfigId)) {
                return 'error: Game Config ID is required but not set';
            }
        }

        // Step 3: Create game server
        $configOverrides = gamecp_ParseConfigOverrides($params);

        $autoDeploy = trim($params['configoption4'] ?? '');
        $shouldAutoDeploy = ($autoDeploy === 'on' || $autoDeploy === '1' || $autoDeploy === 'yes');

        $serverData = array(
            'name' => $serverName,
            'gameId' => $gameConfigId,
            'ownerId' => $userId,
            'startAfterInstall' => true,
            'autoInstall' => $shouldAutoDeploy,
            'configOverrides' => $configOverrides,
            'assignmentType' => 'automatic'
        );

        if (!empty($nodeId)) {
            $serverData['nodeId'] = $nodeId;
        }
        if (!empty($location)) {
            $serverData['location'] = $location;
        }

        $serverId = gamecp_CreateGameServer($apiUrl, $apiKey, $serverData);

        // Check if it returned an error string
        if (is_string($serverId) && strpos($serverId, 'error:') === 0) {
            return 'error: ' . substr($serverId, 6);
        }

        if (!$serverId) {
            return 'error: Failed to create game server (no server ID returned)';
        }

        // Step 4: Store server ID in WHMCS (assignedips = not visible to clients)
        try {
            \WHMCS\Database\Capsule::table('tblhosting')
                ->where('id', $params['serviceid'])
                ->update([
                    'assignedips' => $serverId,
                    'dedicatedip' => '', // Populated with real IP:Port on client area view
                    'domain' => ''
                ]);
        } catch (Exception $e) {
            logModuleCall('gamecp', 'SaveServerId_Error', array(), $e->getMessage(), array(), array());
        }

        return 'success';

    } catch (Exception $e) {
        logModuleCall('gamecp', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString(), array());
        return 'error: ' . $e->getMessage();
    }
}

/**
 * Suspend game server (stops the server).
 */
function gamecp_SuspendAccount(array $params)
{
    try {
        $creds = gamecp_ResolveCredentials($params);
        $serverId = gamecp_GetServerId($params);

        if (empty($serverId)) {
            return 'error: GameCP Server ID not found';
        }

        $response = gamecp_ApiCall($creds['apiUrl'], $creds['apiKey'], "game-servers/{$serverId}/control", 'POST', array(
            'action' => 'stop'
        ));

        if (is_array($response) && !empty($response['_error'])) {
            return 'error: ' . ($response['_message'] ?? 'Failed to suspend server');
        }
        return ($response && isset($response['success'])) ? 'success' : 'error: Failed to suspend server';

    } catch (Exception $e) {
        return 'error: ' . $e->getMessage();
    }
}

/**
 * Unsuspend game server (starts the server).
 */
function gamecp_UnsuspendAccount(array $params)
{
    try {
        $creds = gamecp_ResolveCredentials($params);
        $serverId = gamecp_GetServerId($params);

        if (empty($serverId)) {
            return 'error: GameCP Server ID not found';
        }

        $response = gamecp_ApiCall($creds['apiUrl'], $creds['apiKey'], "game-servers/{$serverId}/control", 'POST', array(
            'action' => 'start'
        ));

        if (is_array($response) && !empty($response['_error'])) {
            return 'error: ' . ($response['_message'] ?? 'Failed to unsuspend server');
        }
        return ($response && isset($response['success'])) ? 'success' : 'error: Failed to unsuspend server';

    } catch (Exception $e) {
        return 'error: ' . $e->getMessage();
    }
}

/**
 * Terminate game server (deletes the server).
 */
function gamecp_TerminateAccount(array $params)
{
    try {
        $creds = gamecp_ResolveCredentials($params);
        $serverId = gamecp_GetServerId($params);

        if (empty($serverId)) {
            return 'error: GameCP Server ID not found';
        }

        $response = gamecp_ApiCall($creds['apiUrl'], $creds['apiKey'], "game-servers/{$serverId}", 'DELETE');

        if (is_array($response) && !empty($response['_error'])) {
            return 'error: ' . ($response['_message'] ?? 'Failed to terminate server');
        }
        return ($response && isset($response['success'])) ? 'success' : 'error: Failed to terminate server';

    } catch (Exception $e) {
        return 'error: ' . $e->getMessage();
    }
}

// ============================================================================
// Client Area
// ============================================================================

/**
 * Client area output with server status, controls, and connection info.
 */
function gamecp_ClientArea(array $params)
{
    try {
        $creds = gamecp_ResolveCredentials($params);
        $apiUrl = $creds['apiUrl'];
        $apiKey = $creds['apiKey'];

        $serverId = gamecp_GetServerId($params);
        $serviceId = $params['serviceid'];
        $message = null;

        // Handle SSO login redirect
        if (isset($_GET['gamecp_login']) && $_GET['gamecp_login'] == '1') {
            $ssoResult = gamecp_ServiceSingleSignOn($params);
            if (is_array($ssoResult) && isset($ssoResult['redirectTo'])) {
                header("Location: " . $ssoResult['redirectTo']);
                exit;
            }
        }

        // Handle POST actions (start, stop, restart)
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gamecp_action']) && !empty($serverId)) {
            $action = $_POST['gamecp_action'];
            if (in_array($action, ['start', 'stop', 'restart'])) {
                $controlResponse = gamecp_ApiCall($apiUrl, $apiKey, "game-servers/{$serverId}/control", 'POST', array(
                    'action' => $action
                ));
                if (is_array($controlResponse) && !empty($controlResponse['_error'])) {
                    $message = 'Failed to ' . $action . ' server: ' . ($controlResponse['_message'] ?? 'Unknown error');
                } elseif ($controlResponse && isset($controlResponse['success'])) {
                    $message = ucfirst($action) . ' command sent successfully.';
                } else {
                    $message = 'Failed to ' . $action . ' server: Unknown error';
                }
            }
        }

        if (empty($serverId)) {
            return array(
                'templatefile' => 'clientarea',
                'vars' => array(
                    'error' => 'Server not yet provisioned or ID missing.',
                    'serviceid' => $serviceId
                )
            );
        }

        // Fetch live server details from API
        $response = gamecp_ApiCall($apiUrl, $apiKey, "game-servers/{$serverId}", 'GET');
        $server = $response['gameServer'] ?? $response;

        if (!$server || (isset($response['error']) && $response['error'])) {
            return array(
                'templatefile' => 'clientarea',
                'vars' => array(
                    'error' => 'Unable to retrieve server status from GameCP.',
                    'serverId' => $serverId,
                    'serviceid' => $serviceId
                )
            );
        }

        // Resolve connection address (IP:Port) from live API data
        $connectionAddress = gamecp_ResolveConnectionAddress($server);

        // Sync the real IP:Port to WHMCS dedicatedip field
        if (!empty($connectionAddress)) {
            try {
                \WHMCS\Database\Capsule::table('tblhosting')
                    ->where('id', $serviceId)
                    ->update(['dedicatedip' => $connectionAddress]);
            } catch (Exception $e) {
                // Non-critical
            }
        }

        return array(
            'templatefile' => 'clientarea',
            'vars' => array(
                'serverName' => $server['name'] ?? 'Game Server',
                'serverId' => $serverId,
                'status' => $server['status'] ?? 'unknown',
                'metrics' => $server['metrics'] ?? array(),
                'gameStatus' => $server['gameStatus'] ?? null,
                'connectionAddress' => $connectionAddress,
                'serviceid' => $serviceId,
                'message' => $message
            )
        );

    } catch (Exception $e) {
        return array(
            'templatefile' => 'clientarea',
            'vars' => array(
                'error' => 'Error: ' . $e->getMessage(),
                'serviceid' => $params['serviceid'] ?? null
            )
        );
    }
}

/**
 * Resolve the player-facing connection address (IP:Port) from API response.
 *
 * @param array $server Game server data from API
 * @return string Connection address (e.g. "178.156.179.34:25565") or empty string
 */
function gamecp_ResolveConnectionAddress(array $server)
{
    $serverPort = '';
    $serverIp = '';

    // Get primary port from assigned ports
    $ports = $server['configOverrides']['gameConfig']['ports']
        ?? $server['gameConfig']['ports']
        ?? array();
    if (!empty($ports)) {
        $serverPort = $ports[0]['host'] ?? $ports[0]['container'] ?? '';
    }

    // Match IP from node's ipAddresses by assignedIpId
    $assignedIpId = $server['assignedIpId'] ?? '';
    $nodeData = $server['nodeId'] ?? null;

    if (is_array($nodeData) && !empty($nodeData['ipAddresses']) && !empty($assignedIpId)) {
        foreach ($nodeData['ipAddresses'] as $ip) {
            if (($ip['_id'] ?? '') === $assignedIpId) {
                $serverIp = !empty($ip['external']) ? $ip['external'] : ($ip['internal'] ?? '');
                break;
            }
        }
    }

    // Fallback to node's primary IP
    if (empty($serverIp) && is_array($nodeData)) {
        $serverIp = $nodeData['primaryIp'] ?? $nodeData['ip'] ?? '';
    }

    return (!empty($serverIp) && !empty($serverPort)) ? $serverIp . ':' . $serverPort : '';
}

// ============================================================================
// API & User Helpers
// ============================================================================

/**
 * Make an API call to GameCP.
 *
 * @param string $apiUrl Base API URL
 * @param string $apiKey API Key
 * @param string $endpoint API endpoint
 * @param string $method HTTP method
 * @param array|null $data Request data
 * @return array|false Response data or false on failure
 */
function gamecp_ApiCall($apiUrl, $apiKey, $endpoint, $method = 'GET', $data = null)
{
    $url = rtrim($apiUrl, '/') . '/api/' . ltrim($endpoint, '/');

    // Test mock support
    if (defined('GAMECP_TEST_MODE') && GAMECP_TEST_MODE === true) {
        if (isset($GLOBALS['_GAMECP_API_MOCKS'])) {
            $mocks = $GLOBALS['_GAMECP_API_MOCKS'];
            if (isset($mocks[$url])) {
                $mock = $mocks[$url];
                return is_string($mock) ? json_decode($mock, true) : $mock;
            }
            if (isset($mocks[$endpoint])) {
                $mock = $mocks[$endpoint];
                return is_string($mock) ? json_decode($mock, true) : $mock;
            }
            foreach ($mocks as $pattern => $mock) {
                if (strpos($url, $pattern) !== false) {
                    return is_string($mock) ? json_decode($mock, true) : $mock;
                }
            }
        }
        return false;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

    $headers = array(
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    );

    if ($data !== null) {
        $jsonData = json_encode($data);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        $headers[] = 'Content-Length: ' . strlen($jsonData);
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        logModuleCall('gamecp', 'ApiCall', array('url' => $url, 'method' => $method), $error, array(), array());
        return false;
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        return json_decode($response, true);
    }

    // Parse the error response body so callers can access the error message
    $decoded = json_decode($response, true);
    logModuleCall('gamecp', 'ApiCall', array('url' => $url, 'method' => $method, 'code' => $httpCode), $response, array(), array());

    // Return a structured error array instead of false so callers get details
    return array(
        '_error' => true,
        '_httpCode' => $httpCode,
        '_message' => isset($decoded['error']) ? $decoded['error'] : 'API request failed (HTTP ' . $httpCode . ')',
        '_code' => isset($decoded['code']) ? $decoded['code'] : 'UNKNOWN',
        '_details' => isset($decoded['details']) ? $decoded['details'] : null,
        '_raw' => $decoded ?: $response,
    );
}

/**
 * Ensure user exists in GameCP (find or create).
 *
 * @param string $apiUrl Base API URL
 * @param string $apiKey API Key
 * @param array $userData User data
 * @return string|null User ID or null on failure
 */
function gamecp_EnsureUserExists($apiUrl, $apiKey, $userData)
{
    $user = gamecp_FindUserByEmail($apiUrl, $apiKey, $userData['email']);

    if ($user) {
        return $user['_id'];
    }

    $response = gamecp_ApiCall($apiUrl, $apiKey, 'settings/users', 'POST', $userData);

    return ($response && isset($response['_id'])) ? $response['_id'] : null;
}

/**
 * Find user by email in GameCP.
 */
function gamecp_FindUserByEmail($apiUrl, $apiKey, $email)
{
    $response = gamecp_ApiCall($apiUrl, $apiKey, "users?email=" . urlencode($email), 'GET');

    if ($response && isset($response['data']['users']) && count($response['data']['users']) > 0) {
        return $response['data']['users'][0];
    }

    return null;
}

/**
 * Create game server in GameCP.
 *
 * @return string|null Server ID (slug) or null on failure
 */
function gamecp_CreateGameServer($apiUrl, $apiKey, $serverData)
{
    $response = gamecp_ApiCall($apiUrl, $apiKey, 'game-servers', 'POST', $serverData);

    // Check for API error response
    if (!$response || (is_array($response) && !empty($response['_error']))) {
        $errorMsg = is_array($response) && isset($response['_message'])
            ? $response['_message']
            : 'Unknown error';
        // Return error string prefixed with 'error:' so caller can detect it
        return 'error:' . $errorMsg;
    }

    if (isset($response['gameServer']['serverId'])) {
        return $response['gameServer']['serverId'];
    }
    if (isset($response['serverId'])) {
        return $response['serverId'];
    }

    return null;
}

/**
 * Parse WHMCS custom fields and configurable options into config overrides.
 *
 * GameCP matches these by environment variable LABEL, so admins can use
 * friendly names like "Server Name" which maps to the env var with that label.
 */
function gamecp_ParseConfigOverrides($params)
{
    $overrides = array();
    $reserved = array('Game Config ID', 'GameCP Server ID', 'GameCP Server Name', 'Node ID', 'Location');

    // Custom fields (except reserved)
    if (!empty($params['customfields']) && is_array($params['customfields'])) {
        foreach ($params['customfields'] as $key => $value) {
            if (in_array($key, $reserved) || $value === '' || $value === null) {
                continue;
            }
            if (strpos($key, 'config_') === 0) {
                $key = substr($key, 7);
            }
            $overrides[$key] = $value;
        }
    }

    // Configurable options
    if (!empty($params['configoptions']) && is_array($params['configoptions'])) {
        foreach ($params['configoptions'] as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            if (strpos($key, 'config_') === 0) {
                $key = substr($key, 7);
            }
            $overrides[$key] = $value;
        }
    }

    return $overrides;
}

// ============================================================================
// SSO (Single Sign-On)
// ============================================================================

/**
 * Service SSO - redirects client to GameCP with auto-login.
 */
function gamecp_ServiceSingleSignOn(array $params)
{
    try {
        $creds = gamecp_ResolveCredentials($params);
        $apiUrl = $creds['apiUrl'];
        $apiKey = $creds['apiKey'];

        $serverId = gamecp_GetServerId($params);
        $clientEmail = $params['clientsdetails']['email'];
        $redirectPath = !empty($serverId) ? '/game-servers/' . $serverId : '/';

        $ssoResponse = gamecp_ApiCall($apiUrl, $apiKey, 'auth/sso-token', 'POST', array(
            'email' => $clientEmail,
            'redirectTo' => $redirectPath,
            'baseUrl' => rtrim($apiUrl, '/')
        ));

        if ($ssoResponse && isset($ssoResponse['ssoUrl'])) {
            return array('success' => true, 'redirectTo' => $ssoResponse['ssoUrl']);
        }

        return array('success' => true, 'redirectTo' => rtrim($apiUrl, '/'));

    } catch (Exception $e) {
        logModuleCall('gamecp', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString(), array());
        $fallbackUrl = $params['serverhostname'] ?: ($params['serverip'] ?? '');
        if (!empty($fallbackUrl) && strpos($fallbackUrl, 'http') === false) {
            $fallbackUrl = "https://" . $fallbackUrl;
        }
        return array('success' => true, 'redirectTo' => rtrim($fallbackUrl, '/'));
    }
}

/**
 * Admin SSO - redirects admin to GameCP settings.
 */
function gamecp_AdminSingleSignOn(array $params)
{
    try {
        $apiUrl = $params['serverhostname'] ?: ($params['serverip'] ?? '');
        if (!empty($apiUrl) && strpos($apiUrl, 'http') === false) {
            $apiUrl = "https://" . $apiUrl;
        }
        return array('success' => true, 'redirectTo' => rtrim($apiUrl, '/') . '/settings');

    } catch (Exception $e) {
        logModuleCall('gamecp', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString(), array());
        $apiUrl = $params['serverhostname'] ?: ($params['serverip'] ?? '');
        if (!empty($apiUrl) && strpos($apiUrl, 'http') === false) {
            $apiUrl = "https://" . $apiUrl;
        }
        return array('success' => true, 'redirectTo' => rtrim($apiUrl, '/'));
    }
}

// ============================================================================
// Utility
// ============================================================================

/**
 * Save custom field value via WHMCS LocalAPI.
 */
function gamecp_SaveCustomField($serviceId, $fieldName, $fieldValue)
{
    try {
        $results = localAPI('UpdateClientProduct', array(
            'serviceid' => $serviceId,
            'customfields' => base64_encode(serialize(array($fieldName => $fieldValue)))
        ));

        if ($results['result'] == 'success') {
            return true;
        }

        logModuleCall('gamecp', 'SaveCustomField', array('serviceid' => $serviceId), 'LocalAPI failed', $results, array());
        return false;

    } catch (Exception $e) {
        logModuleCall('gamecp', 'SaveCustomField', array('serviceid' => $serviceId), $e->getMessage(), array(), array());
        return false;
    }
}
