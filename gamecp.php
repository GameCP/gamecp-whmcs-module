<?php
/**
 * GameCP Provisioning Module for WHMCS
 * 
 * This module integrates with GameCP to automatically provision game servers
 * when orders are paid in WHMCS.
 * 
 * @copyright Copyright (c) 2024
 * @license See LICENSE
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * Define module configuration metadata.
 *
 * @return array
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
        // Module branding & info
        'Description' => 'Automated game server provisioning and management. Instantly deploy, control, and monitor game servers for your customers with full SSO integration.',
        'Author' => 'GameCP',
        'AuthorURL' => 'https://gamecp.com',
        'Version' => '1.1.0',
        'Category' => 'Game Servers',
        'SupportURL' => 'https://gamecp.com/support',
        'DocumentationURL' => 'https://docs.gamecp.com/whmcs',
    );
}

/**
 * Define module configuration parameters.
 *
 * @return array
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
    );
}

/**
 * Test connection to GameCP server.
 *
 * @param array $params All server parameters
 *
 * @return array Success/error response
 */
function gamecp_TestConnection(array $params)
{
    try {
        $apiUrl = $params['serverhostname'] ?: $params['serverip'];
        $apiKey = $params['serveraccesshash'];

        // Ensure URL has protocol - default to HTTPS
        if (!empty($apiUrl) && strpos($apiUrl, 'http') === false) {
            // Use HTTPS by default, or HTTP if explicitly not secure
            $apiUrl = "https://" . $apiUrl;
        }

        // Validate required fields
        if (empty($apiUrl)) {
            return array(
                'success' => false,
                'error' => 'Hostname or IP Address is required',
            );
        }

        if (empty($apiKey)) {
            return array(
                'success' => false,
                'error' => 'API Key (Access Hash) is required',
            );
        }

        // Tenant Slug validation removed - using hostname context

        // Try to fetch users list as a connection test
        $testUrl = rtrim($apiUrl, '/') . '/api/users?limit=1';

        // Manual curl for better error reporting
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $testUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Follow redirects
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5); // Max 5 redirects
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL); // Where did we end up?
        $redirectCount = curl_getinfo($ch, CURLINFO_REDIRECT_COUNT);
        curl_close($ch);

        // Log the attempt
        logModuleCall(
            'gamecp',
            'TestConnection',
            array(
                'url' => $testUrl,
                'hostname' => $apiUrl,
                'has_api_key' => !empty($apiKey),
            ),
            $response, // Log raw response as string
            array(
                'http_code' => $httpCode,
                'curl_error' => $curlError,
                'effective_url' => $effectiveUrl,
                'redirect_count' => $redirectCount,
            ),
            array()
        );

        if ($curlError) {
            return array(
                'success' => false,
                'error' => 'Connection error: ' . $curlError,
            );
        }

        if ($httpCode === 0) {
            return array(
                'success' => false,
                'error' => 'Could not connect to ' . $apiUrl . '. Please check the hostname is correct and accessible.',
            );
        }

        if ($httpCode === 401) {
            return array(
                'success' => false,
                'error' => 'Authentication failed. Please check your API Key (Access Hash).',
            );
        }

        if ($httpCode === 404) {
            return array(
                'success' => false,
                'error' => 'API endpoint not found. Please verify your Tenant Slug is correct.',
            );
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            return array(
                'success' => false,
                'error' => 'API returned HTTP ' . $httpCode . '. Check Module Log for details.',
            );
        }

        $data = json_decode($response, true);

        if (!$data) {
            $preview = substr($response, 0, 200);
            return array(
                'success' => false,
                'error' => 'Received invalid JSON. Response preview: ' . $preview,
            );
        }

        // Check if response has expected structure
        // GameCP might return different structures, let's be flexible
        if (!isset($data['users']) && !isset($data['data'])) {
            $keys = implode(', ', array_keys($data));
            $preview = substr(json_encode($data), 0, 200);
            return array(
                'success' => false,
                'error' => 'Got keys: [' . $keys . ']. Preview: ' . $preview,
            );
        }

        return array(
            'success' => true,
            'error' => '', // WHMCS expects this even on success
        );

    } catch (Exception $e) {
        logModuleCall(
            'gamecp',
            __FUNCTION__,
            $params,
            $e->getMessage(),
            $e->getTraceAsString(),
            array()
        );

        return array(
            'success' => false,
            'error' => 'Connection test failed: ' . $e->getMessage(),
        );
    }
}

/**
 * Create game server when order is activated.
 *
 * @param array $params All product/service parameters
 *
 * @return string Success/Failure message
 */
function gamecp_CreateAccount(array $params)
{
    try {
        // API details from Server configuration
        $apiUrl = $params['serverhostname'] ?: $params['serverip'];
        $apiKey = $params['serveraccesshash']; // Using Access Hash for the API Key

        // Debug: Log what we received
        logModuleCall(
            'gamecp',
            'CreateAccount_Debug',
            $params,
            'Debugging server credentials',
            array(),
            array()
        );

        // WHMCS Server Group workaround: fetch server details from database
        logModuleCall('gamecp', 'ServerLookup_Start', array(
            'apiKey_before' => $apiKey ?: 'EMPTY',
            'serverid' => $params['serverid'] ?? 'NOT SET',
            'pid' => $params['pid'] ?? 'NOT SET',
            'will_lookup' => (empty($apiKey)) ? 'YES' : 'NO'
        ), '', array(), array());

        if (empty($apiKey)) {
            try {
                $serverData = null;

                // Try direct serverid first
                if (!empty($params['serverid']) && $params['serverid'] > 0) {
                    $serverData = \WHMCS\Database\Capsule::table('tblservers')
                        ->where('id', $params['serverid'])
                        ->first();
                }

                // If no server, try to get from product's server group
                if (!$serverData && !empty($params['pid'])) {
                    // Get the product's server group
                    $product = \WHMCS\Database\Capsule::table('tblproducts')
                        ->where('id', $params['pid'])
                        ->first();

                    logModuleCall('gamecp', 'ServerLookup_Product', array(
                        'pid' => $params['pid'],
                        'servergroup' => $product->servergroup ?? 'N/A',
                    ), '', array(), array());

                    if ($product && $product->servergroup) {
                        // Get first GameCP server from this group
                        $serverData = \WHMCS\Database\Capsule::table('tblservers')
                            ->join('tblservergroupsrel', 'tblservers.id', '=', 'tblservergroupsrel.serverid')
                            ->where('tblservergroupsrel.groupid', $product->servergroup)
                            ->where('tblservers.type', 'gamecp')
                            ->first();
                    }
                }

                logModuleCall('gamecp', 'ServerLookup_Result', array(
                    'found' => $serverData ? 'YES' : 'NO',
                    'hostname' => $serverData->hostname ?? 'N/A',
                    'has_accesshash' => !empty($serverData->accesshash) ? 'YES' : 'NO',
                ), '', array(), array());

                if ($serverData) {
                    $apiUrl = $serverData->hostname ?: $serverData->ipaddress;
                    $apiKey = $serverData->accesshash;
                }
            } catch (\Exception $e) {
                logModuleCall('gamecp', 'ServerLookup_Error', array(), $e->getMessage(), array(), array());
            }
        }

        logModuleCall('gamecp', 'ServerLookup_Final', array(
            'apiUrl' => $apiUrl ?: 'EMPTY',
            'apiKey' => $apiKey ? 'SET (' . strlen($apiKey) . ' chars)' : 'EMPTY',
        ), '', array(), array());

        // Ensure URL has protocol
        // Ensure URL has protocol
        if (!empty($apiUrl) && strpos($apiUrl, 'http') === false) {
            $apiUrl = "https://" . $apiUrl;
        }

        // Updated Config Option Mappings:
        // 1: Game Config ID
        // 2: Node ID
        // 3: Location
        $gameConfigId = trim($params['configoption1']); // Game Config ID
        $nodeId = trim($params['configoption2']); // Node ID
        $location = trim($params['configoption3']); // Location

        // Extract domain info from WHMCS
        $domain = $params['domain'];

        // Get client information
        $clientId = $params['clientsdetails']['userid'];
        $clientEmail = $params['clientsdetails']['email'];
        $clientFirstName = $params['clientsdetails']['firstname'];
        $clientLastName = $params['clientsdetails']['lastname'];

        // Get WHMCS service/order ID for friendly naming
        $serviceId = $params['serviceid'] ?? '';

        // Generate server name with priority:
        // 1. WHMCS domain field (if set by product config)
        // 2. Friendly auto-generated name using WHMCS order ID
        if (!empty($domain)) {
            $serverName = $domain;
        } else {
            $serverName = "Game Server #" . ($serviceId ?: $clientId);
        }

        // Log module activation
        logModuleCall(
            'gamecp',
            __FUNCTION__,
            $params,
            array($clientEmail, $serverName),
            array(),
            array()
        );

        // Step 1: Ensure user exists in GameCP
        // Use the password generated by WHMCS for this service (visible in admin area)
        // Note: usage of 'password' param requires it to be unhashed in module settings or standard provision
        $servicePassword = $params['password'];

        $userId = gamecp_EnsureUserExists($apiUrl, $apiKey, array(
            'email' => $clientEmail,
            'firstName' => $clientFirstName,
            'lastName' => $clientLastName,
            'role' => 'user',
            'password' => $servicePassword
        ));

        // Update the Service Username in WHMCS to match the Email (Standardize display)
        try {
            \WHMCS\Database\Capsule::table('tblhosting')
                ->where('id', $params['serviceid'])
                ->update(['username' => $clientEmail]);
        } catch (Exception $e) {
            // Ignore DB update errors, strictly cosmetic
        }

        if (!$userId) {
            return 'error: Could not find or create user in GameCP';
        }

        // Step 2: Get game config if not provided
        if (empty($gameConfigId)) {
            // Try to get from custom fields
            $gameConfigId = $params['customfields']['Game Config ID'] ?? '';
            if (empty($gameConfigId)) {
                return 'error: Game Config ID is required but not set';
            }
        }

        // Step 3: Create game server
        $configOverrides = gamecp_ParseConfigOverrides($params);

        // Debug log the configOverrides being sent
        logModuleCall(
            'gamecp',
            'CreateAccount_ConfigOverrides',
            array(
                'configoptions_raw' => $params['configoptions'] ?? [],
                'customfields_raw' => $params['customfields'] ?? [],
            ),
            'Sending configOverrides: ' . json_encode($configOverrides),
            array(),
            array()
        );

        $serverData = array(
            'name' => $serverName,
            'gameId' => $gameConfigId,
            'ownerId' => $userId,
            'startAfterInstall' => true,
            'configOverrides' => $configOverrides,
            'assignmentType' => 'automatic' // Always use automatic port assignment
        );

        // Include nodeId if set (forces deployment to specific node)
        if (!empty($nodeId)) {
            $serverData['nodeId'] = $nodeId;
        }

        // Include location if set
        if (!empty($location)) {
            $serverData['location'] = $location;
        }

        $serverId = gamecp_CreateGameServer($apiUrl, $apiKey, $serverData);

        if (!$serverId) {
            return 'error: Failed to create game server';
        }

        // Step 4: Store GameCP server ID in WHMCS service
        // Use dedicatedip field for reliable storage (always exists)
        try {
            \WHMCS\Database\Capsule::table('tblhosting')
                ->where('id', $params['serviceid'])
                ->update([
                    'dedicatedip' => $serverId,
                    'domain' => $serverName
                ]);

            logModuleCall('gamecp', 'SaveServerId', array(
                'serviceid' => $params['serviceid'],
                'serverId' => $serverId
            ), 'Saved server ID to dedicatedip field', array(), array());
        } catch (Exception $e) {
            logModuleCall('gamecp', 'SaveServerId_Error', array(
                'serviceid' => $params['serviceid']
            ), $e->getMessage(), array(), array());
        }

        return 'success';

    } catch (Exception $e) {
        logModuleCall(
            'gamecp',
            __FUNCTION__,
            $params,
            $e->getMessage(),
            $e->getTraceAsString(),
            array()
        );

        return 'error: ' . $e->getMessage();
    }
}

/**
 * Suspend game server.
 *
 * @param array $params All product/service parameters
 *
 * @return string Success/Failure message
 */
function gamecp_SuspendAccount(array $params)
{
    try {
        $apiUrl = $params['serverhostname'] ?: $params['serverip'];
        $apiKey = $params['serveraccesshash'];

        // WHMCS Server Group workaround: fetch server details from database
        if (empty($apiKey) && !empty($params['serverid'])) {
            $serverData = \WHMCS\Database\Capsule::table('tblservers')
                ->where('id', $params['serverid'])
                ->first();
            if ($serverData) {
                $apiUrl = $serverData->hostname ?: $serverData->ipaddress;
                $apiKey = $serverData->accesshash;
            }
        }

        // Fallback to product's server group
        if (empty($apiKey) && !empty($params['pid'])) {
            $product = \WHMCS\Database\Capsule::table('tblproducts')
                ->where('id', $params['pid'])
                ->first();
            if ($product && $product->servergroup) {
                $serverData = \WHMCS\Database\Capsule::table('tblservers')
                    ->join('tblservergroupsrel', 'tblservers.id', '=', 'tblservergroupsrel.serverid')
                    ->where('tblservergroupsrel.groupid', $product->servergroup)
                    ->where('tblservers.type', 'gamecp')
                    ->first();
                if ($serverData) {
                    $apiUrl = $serverData->hostname ?: $serverData->ipaddress;
                    $apiKey = $serverData->accesshash;
                }
            }
        }

        // Ensure URL has protocol
        if (!empty($apiUrl) && strpos($apiUrl, 'http') === false) {
            $apiUrl = "https://" . $apiUrl;
        }

        // Get server ID from dedicatedip field
        $serverId = $params['model']->dedicatedip ?? $params['customfields']['GameCP Server ID'] ?? '';

        if (empty($serverId)) {
            return 'error: GameCP Server ID not found';
        }

        // Call GameCP API to stop the server
        $response = gamecp_ApiCall($apiUrl, $apiKey, "game-servers/{$serverId}/control", 'POST', array(
            'action' => 'stop'
        ));

        if ($response && isset($response['success'])) {
            return 'success';
        }

        return 'error: Failed to suspend server';

    } catch (Exception $e) {
        return 'error: ' . $e->getMessage();
    }
}

/**
 * Unsuspend game server.
 *
 * @param array $params All product/service parameters
 *
 * @return string Success/Failure message
 */
function gamecp_UnsuspendAccount(array $params)
{
    try {
        $apiUrl = $params['serverhostname'] ?: $params['serverip'];
        $apiKey = $params['serveraccesshash'];

        // Server lookup (same as Suspend)
        if (empty($apiKey) && !empty($params['serverid'])) {
            $serverData = \WHMCS\Database\Capsule::table('tblservers')->where('id', $params['serverid'])->first();
            if ($serverData) {
                $apiUrl = $serverData->hostname ?: $serverData->ipaddress;
                $apiKey = $serverData->accesshash;
            }
        }
        if (empty($apiKey) && !empty($params['pid'])) {
            $product = \WHMCS\Database\Capsule::table('tblproducts')->where('id', $params['pid'])->first();
            if ($product && $product->servergroup) {
                $serverData = \WHMCS\Database\Capsule::table('tblservers')
                    ->join('tblservergroupsrel', 'tblservers.id', '=', 'tblservergroupsrel.serverid')
                    ->where('tblservergroupsrel.groupid', $product->servergroup)
                    ->where('tblservers.type', 'gamecp')->first();
                if ($serverData) {
                    $apiUrl = $serverData->hostname ?: $serverData->ipaddress;
                    $apiKey = $serverData->accesshash;
                }
            }
        }

        if (!empty($apiUrl) && strpos($apiUrl, 'http') === false) {
            $apiUrl = "https://" . $apiUrl;
        }

        $serverId = $params['model']->dedicatedip ?? $params['customfields']['GameCP Server ID'] ?? '';

        if (empty($serverId)) {
            return 'error: GameCP Server ID not found';
        }

        // Call GameCP API to start the server
        $response = gamecp_ApiCall($apiUrl, $apiKey, "game-servers/{$serverId}/control", 'POST', array('action' => 'start'));

        if ($response && isset($response['success'])) {
            return 'success';
        }

        return 'error: Failed to unsuspend server';

    } catch (Exception $e) {
        return 'error: ' . $e->getMessage();
    }
}

/**
 * Terminate game server.
 *
 * @param array $params All product/service parameters
 *
 * @return string Success/Failure message
 */
function gamecp_TerminateAccount(array $params)
{
    try {
        $apiUrl = $params['serverhostname'] ?: $params['serverip'];
        $apiKey = $params['serveraccesshash'];

        // Server lookup
        if (empty($apiKey) && !empty($params['serverid'])) {
            $serverData = \WHMCS\Database\Capsule::table('tblservers')->where('id', $params['serverid'])->first();
            if ($serverData) {
                $apiUrl = $serverData->hostname ?: $serverData->ipaddress;
                $apiKey = $serverData->accesshash;
            }
        }
        if (empty($apiKey) && !empty($params['pid'])) {
            $product = \WHMCS\Database\Capsule::table('tblproducts')->where('id', $params['pid'])->first();
            if ($product && $product->servergroup) {
                $serverData = \WHMCS\Database\Capsule::table('tblservers')
                    ->join('tblservergroupsrel', 'tblservers.id', '=', 'tblservergroupsrel.serverid')
                    ->where('tblservergroupsrel.groupid', $product->servergroup)
                    ->where('tblservers.type', 'gamecp')->first();
                if ($serverData) {
                    $apiUrl = $serverData->hostname ?: $serverData->ipaddress;
                    $apiKey = $serverData->accesshash;
                }
            }
        }

        if (!empty($apiUrl) && strpos($apiUrl, 'http') === false) {
            $apiUrl = "https://" . $apiUrl;
        }

        $serverId = $params['model']->dedicatedip ?? $params['customfields']['GameCP Server ID'] ?? '';

        if (empty($serverId)) {
            return 'error: GameCP Server ID not found';
        }

        // Call GameCP API to delete the server
        $response = gamecp_ApiCall($apiUrl, $apiKey, "game-servers/{$serverId}", 'DELETE');

        if ($response && isset($response['success'])) {
            return 'success';
        }

        return 'error: Failed to terminate server';

    } catch (Exception $e) {
        return 'error: ' . $e->getMessage();
    }
}

/**
 * Get server status for the client area.
 *
 * @param array $params All product/service parameters
 *
 * @return array Template file and variables
 */
function gamecp_ClientArea(array $params)
{
    try {
        $apiUrl = $params['serverhostname'] ?: $params['serverip'];
        $apiKey = $params['serveraccesshash'];

        // Robust credential lookup for server groups
        if (empty($apiKey) && !empty($params['serverid'])) {
            $serverData = \WHMCS\Database\Capsule::table('tblservers')
                ->where('id', $params['serverid'])
                ->first();
            if ($serverData) {
                $apiUrl = $serverData->hostname ?: $serverData->ipaddress;
                $apiKey = $serverData->accesshash;
            }
        }

        if (empty($apiKey) && !empty($params['pid'])) {
            $product = \WHMCS\Database\Capsule::table('tblproducts')
                ->where('id', $params['pid'])
                ->first();
            if ($product && $product->servergroup) {
                $serverData = \WHMCS\Database\Capsule::table('tblservers')
                    ->join('tblservergroupsrel', 'tblservers.id', '=', 'tblservergroupsrel.serverid')
                    ->where('tblservergroupsrel.groupid', $product->servergroup)
                    ->where('tblservers.type', 'gamecp')
                    ->first();
                if ($serverData) {
                    $apiUrl = $serverData->hostname ?: $serverData->ipaddress;
                    $apiKey = $serverData->accesshash;
                }
            }
        }

        // Ensure URL has protocol
        if (!empty($apiUrl) && strpos($apiUrl, 'http') === false) {
            $apiUrl = "https://" . $apiUrl;
        }

        $serverId = $params['model']->dedicatedip ?? $params['customfields']['GameCP Server ID'] ?? '';
        $serverName = $params['customfields']['GameCP Server Name'] ?? 'Game Server';
        $serviceId = $params['serviceid'];
        $message = null;

        // Handle login request via SSO function
        if (isset($_GET['gamecp_login']) && $_GET['gamecp_login'] == '1') {
            $ssoResult = gamecp_ServiceSingleSignOn($params);
            if (is_array($ssoResult) && isset($ssoResult['redirectTo'])) {
                header("Location: " . $ssoResult['redirectTo']);
                exit;
            }
        }

        if (empty($serverId)) {
            return array(
                'templatefile' => 'clientarea',
                'vars' => array(
                    'error' => 'Server not yet provisioned or ID missing.',
                    'serverName' => $serverName,
                    'serviceid' => $serviceId
                )
            );
        }

        // Fetch server details and metrics from API
        $response = gamecp_ApiCall($apiUrl, $apiKey, "game-servers/{$serverId}", 'GET');

        // Handle API wrapper "gameServer" or direct response
        $server = $response['gameServer'] ?? $response;

        if (!$server || (isset($response['error']) && $response['error'])) {
            return array(
                'templatefile' => 'clientarea',
                'vars' => array(
                    'error' => 'Unable to retrieve server status from GameCP.',
                    'serverName' => $serverName,
                    'serverId' => $serverId,
                    'serviceid' => $serviceId
                )
            );
        }

        return array(
            'templatefile' => 'clientarea',
            'vars' => array(
                'serverName' => $server['name'] ?? $serverName,
                'serverId' => $serverId,
                'status' => $server['status'] ?? 'unknown',
                'metrics' => $server['metrics'] ?? array(),
                'gameStatus' => $server['gameStatus'] ?? null,
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
 * Helper function to make API calls to GameCP.
 *
 * @param string $apiUrl Base API URL
 * @param string $apiKey API Key
 * @param string $apiUrl Base API URL
 * @param string $apiKey API Key
 * @param string $endpoint API endpoint
 * @param string $method HTTP method
 * @param array $data Request data
 *
 * @return array|false Response data or false on failure
 */
function gamecp_ApiCall($apiUrl, $apiKey, $endpoint, $method = 'GET', $data = null)
{
    // Use /api/ - middleware now supports API key auth
    $url = rtrim($apiUrl, '/') . '/api/' . ltrim($endpoint, '/');

    // Check for test mocks (simple global variable approach)
    if (defined('GAMECP_TEST_MODE') && GAMECP_TEST_MODE === true) {
        if (isset($GLOBALS['_GAMECP_API_MOCKS'])) {
            $mocks = $GLOBALS['_GAMECP_API_MOCKS'];
            // Try exact URL match first
            if (isset($mocks[$url])) {
                $mock = $mocks[$url];
                return is_string($mock) ? json_decode($mock, true) : $mock;
            }
            // Try endpoint matching (most common)
            if (isset($mocks[$endpoint])) {
                $mock = $mocks[$endpoint];
                return is_string($mock) ? json_decode($mock, true) : $mock;
            }
            // Try pattern matching on URL
            foreach ($mocks as $pattern => $mock) {
                if (strpos($url, $pattern) !== false) {
                    return is_string($mock) ? json_decode($mock, true) : $mock;
                }
            }
        }
        // In test mode, if no mock found, fail fast instead of making real API call
        logModuleCall(
            'gamecp',
            'ApiCall',
            array('url' => $url, 'method' => $method, 'endpoint' => $endpoint),
            'TEST MODE: No mock found for API call',
            array(),
            array()
        );
        return false;
    }

    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Follow redirects
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5); // Max 5 redirects
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    // Fast timeout in test mode, normal timeout otherwise
    $timeout = (defined('GAMECP_TEST_MODE') && GAMECP_TEST_MODE === true) ? 1 : 30;
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);

    $headers = array(
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    );

    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        $headers[] = 'Content-Length: ' . strlen(json_encode($data));
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    // Build curl command for debugging
    $curlCmd = "curl -X {$method} '{$url}'";
    foreach ($headers as $header) {
        $curlCmd .= " -H '{$header}'";
    }
    if ($data !== null) {
        $curlCmd .= " -d '" . json_encode($data) . "'";
    }

    // Log the curl command for easy testing
    logModuleCall(
        'gamecp',
        'ApiCall_Debug',
        array('curl_command' => $curlCmd),
        'Use this command to test the API call manually',
        array(),
        array()
    );

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    curl_close($ch);

    if ($error) {
        logModuleCall(
            'gamecp',
            'ApiCall',
            array('url' => $url, 'method' => $method),
            $error,
            array(),
            array()
        );
        return false;
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        return json_decode($response, true);
    }

    logModuleCall(
        'gamecp',
        'ApiCall',
        array('url' => $url, 'method' => $method, 'code' => $httpCode),
        $response,
        array(),
        array()
    );

    return false;
}

/**
 * Ensure user exists in GameCP.
 *
 * @param string $apiUrl Base API URL
 * @param string $apiKey API Key
 * @param string $tenantSlug Tenant slug
 * @param array $userData User data
 *
 * @return string|null User ID or null on failure
 */
function gamecp_EnsureUserExists($apiUrl, $apiKey, $userData)
{
    // Try to find existing user
    $user = gamecp_FindUserByEmail($apiUrl, $apiKey, $userData['email']);

    if ($user) {
        return $user['_id'];
    }

    // Create new user
    $response = gamecp_ApiCall($apiUrl, $apiKey, 'settings/users', 'POST', $userData);

    if ($response && isset($response['_id'])) {
        return $response['_id'];
    }

    return null;
}

/**
 * Find user by email in GameCP.
 *
 * @param string $apiUrl Base API URL
 * @param string $apiKey API Key
 * @param string $tenantSlug Tenant slug
 * @param string $email User email
 *
 * @return array|null User data or null
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
 * @param string $apiUrl Base API URL
 * @param string $apiKey API Key
 * @param string $tenantSlug Tenant slug
 * @param array $serverData Server data
 *
 * @return string|null Server ID or null on failure
 */
function gamecp_CreateGameServer($apiUrl, $apiKey, $serverData)
{
    // Tenant context is handled by headers/domain
    $response = gamecp_ApiCall($apiUrl, $apiKey, 'game-servers', 'POST', $serverData);

    // Response format: {"success":true,"gameServer":{"_id":"...","serverId":"minecraft-xxx",...}}
    // Return serverId (used for API calls) not _id
    if ($response && isset($response['gameServer']['serverId'])) {
        return $response['gameServer']['serverId'];
    }

    // Fallback for direct response
    if ($response && isset($response['serverId'])) {
        return $response['serverId'];
    }

    return null;
}

/**
 * Parse configuration overrides from WHMCS params.
 * 
 * Collects values from:
 * - Custom Fields (by field name)
 * - Configurable Options (by option name)
 * 
 * GameCP will match these by environment variable LABEL, so admins can use
 * friendly names like "Server Name" which maps to the env var with that label.
 *
 * @param array $params WHMCS parameters
 *
 * @return array Configuration overrides keyed by label
 */
function gamecp_ParseConfigOverrides($params)
{
    $overrides = array();

    // Reserved field names that are NOT config overrides
    $reserved = array(
        'Game Config ID',
        'GameCP Server ID',
        'GameCP Server Name',
        'Node ID',
        'Location',
    );

    // 1. Parse ALL custom fields (except reserved ones)
    if (!empty($params['customfields']) && is_array($params['customfields'])) {
        foreach ($params['customfields'] as $key => $value) {
            // Skip reserved fields and empty values
            if (in_array($key, $reserved) || $value === '' || $value === null) {
                continue;
            }

            // Legacy support: strip 'config_' prefix if present
            if (strpos($key, 'config_') === 0) {
                $key = substr($key, 7);
            }

            $overrides[$key] = $value;
        }
    }

    // 2. Parse ALL Configurable Options
    if (!empty($params['configoptions']) && is_array($params['configoptions'])) {
        foreach ($params['configoptions'] as $key => $value) {
            // Skip empty values
            if ($value === '' || $value === null) {
                continue;
            }

            // Legacy support: strip 'config_' prefix if present
            if (strpos($key, 'config_') === 0) {
                $key = substr($key, 7);
            }

            $overrides[$key] = $value;
        }
    }

    // Log what we're passing for debugging
    if (!empty($overrides)) {
        logModuleCall(
            'gamecp',
            'ParseConfigOverrides',
            array(
                'customfields_count' => count($params['customfields'] ?? []),
                'configoptions_count' => count($params['configoptions'] ?? []),
            ),
            'Passing ' . count($overrides) . ' overrides: ' . json_encode(array_keys($overrides)),
            array(),
            array()
        );
    }

    return $overrides;
}

/**
 * Install game server (deploy/install the game on an existing server).
 *
 * @param array $params All product/service parameters
 *
 * @return string Success/Failure message
 */
function gamecp_InstallGameServer(array $params)
{
    try {
        $apiUrl = $params['serverhostname'] ?: $params['serverip'];
        $apiKey = $params['serveraccesshash'];


        // Ensure URL has protocol
        if (!empty($apiUrl) && strpos($apiUrl, 'http') === false) {
            $apiUrl = "https://" . $apiUrl;
        }

        $serverId = $params['customfields']['GameCP Server ID'];

        if (empty($serverId)) {
            return 'error: GameCP Server ID not found';
        }

        // Get server details to check if it needs installation
        $server = gamecp_ApiCall($apiUrl, $apiKey, "game-servers/{$serverId}", 'GET');

        if (!$server) {
            return 'error: Game server not found';
        }

        // Check if server is already installed or installing
        if (in_array($server['status'] ?? '', ['installing', 'running', 'stopped'])) {
            return 'error: Game server is already installed or installing';
        }

        // Get the actual server _id if serverId is not an ObjectId
        $actualServerId = $serverId;
        if (!preg_match('/^[0-9a-fA-F]{24}$/', $serverId)) {
            // serverId is not an ObjectId, try to find by serverId field
            $servers = gamecp_ApiCall($apiUrl, $apiKey, "game-servers?serverId=" . urlencode($serverId), 'GET');
            if ($servers && isset($servers['servers']) && count($servers['servers']) > 0) {
                $actualServerId = $servers['servers'][0]['_id'] ?? $serverId;
            }
        }

        // Deploy/install the game server
        // Deploy/install the game server
        $response = gamecp_ApiCall($apiUrl, $apiKey, "game-servers/{$actualServerId}/deploy", 'POST');

        if ($response && (isset($response['success']) || isset($response['message']))) {
            return 'success';
        }

        $errorMsg = isset($response['error']) ? $response['error'] : 'Unknown error';
        return 'error: Failed to install game server: ' . $errorMsg;

    } catch (Exception $e) {
        logModuleCall(
            'gamecp',
            __FUNCTION__,
            $params,
            $e->getMessage(),
            $e->getTraceAsString(),
            array()
        );

        return 'error: ' . $e->getMessage();
    }
}

/**
 * Uninstall game server (remove game files but keep server record).
 * Note: This will delete the server from GameCP. To keep the server record,
 * users should use the stop/suspend function instead.
 *
 * @param array $params All product/service parameters
 *
 * @return string Success/Failure message
 */
function gamecp_UninstallGameServer(array $params)
{
    try {
        $apiUrl = $params['serverhostname'] ?: $params['serverip'];
        $apiKey = $params['serveraccesshash'];


        // Ensure URL has protocol
        if (!empty($apiUrl) && strpos($apiUrl, 'http') === false) {
            $apiUrl = "https://" . $apiUrl;
        }

        $serverId = $params['customfields']['GameCP Server ID'];

        if (empty($serverId)) {
            return 'error: GameCP Server ID not found';
        }

        // Get server details first to verify it exists
        $server = gamecp_ApiCall($apiUrl, $apiKey, "game-servers/{$serverId}", 'GET');

        // If not found by _id, try searching by serverId
        if (!$server || (isset($server['error']) && $server['error'])) {
            $servers = gamecp_ApiCall($apiUrl, $apiKey, "game-servers?serverId=" . urlencode($serverId), 'GET');
            if ($servers && isset($servers['servers']) && count($servers['servers']) > 0) {
                $server = $servers['servers'][0];
                // Use the _id for deletion
                $serverId = $server['_id'] ?? $serverId;
            } else {
                return 'error: Game server not found';
            }
        }

        // Stop the server first if it's running
        // Use the actual server _id for the control endpoint
        $controlServerId = isset($server['_id']) ? $server['_id'] : $serverId;
        if (($server['status'] ?? '') === 'running') {
            gamecp_ApiCall($apiUrl, $apiKey, "game-servers/{$controlServerId}/control", 'POST', array(
                'action' => 'stop'
            ));
            // Wait a moment for the server to stop
            sleep(2);
        }

        // Use the _id for deletion if we have it
        $deleteServerId = isset($server['_id']) ? $server['_id'] : $serverId;

        // Delete the game server (this removes the server and all game files)
        // The API will handle cleanup on the node server
        // Delete the game server (this removes the server and all game files)
        // The API will handle cleanup on the node server
        $response = gamecp_ApiCall($apiUrl, $apiKey, "game-servers/{$deleteServerId}", 'DELETE');

        if ($response && (isset($response['success']) || isset($response['message']) || !isset($response['error']))) {
            // Clear the custom fields since server is deleted
            gamecp_SaveCustomField($params['serviceid'], 'GameCP Server ID', '');
            gamecp_SaveCustomField($params['serviceid'], 'GameCP Server Name', '');
            return 'success';
        }

        $errorMsg = isset($response['error']) ? $response['error'] : 'Unknown error';
        return 'error: Failed to uninstall game server: ' . $errorMsg;

    } catch (Exception $e) {
        logModuleCall(
            'gamecp',
            __FUNCTION__,
            $params,
            $e->getMessage(),
            $e->getTraceAsString(),
            array()
        );

        return 'error: ' . $e->getMessage();
    }
}

/**
 * Service Single Sign-On for client area.
 * Generates a login URL that redirects the client to GameCP with auto-login.
 *
 * @param array $params All product/service parameters
 *
 * @return array Login redirect data
 */
function gamecp_ServiceSingleSignOn(array $params)
{
    try {
        $apiUrl = $params['serverhostname'] ?: $params['serverip'];
        $apiKey = $params['serveraccesshash'];

        // WHMCS Server Group workaround: fetch server details from database
        if (empty($apiKey) && !empty($params['serverid'])) {
            $serverData = \WHMCS\Database\Capsule::table('tblservers')
                ->where('id', $params['serverid'])
                ->first();
            if ($serverData) {
                $apiUrl = $serverData->hostname ?: $serverData->ipaddress;
                $apiKey = $serverData->accesshash;
            }
        }

        // Fallback to product's server group
        if (empty($apiKey) && !empty($params['pid'])) {
            $product = \WHMCS\Database\Capsule::table('tblproducts')
                ->where('id', $params['pid'])
                ->first();
            if ($product && $product->servergroup) {
                $serverData = \WHMCS\Database\Capsule::table('tblservers')
                    ->join('tblservergroupsrel', 'tblservers.id', '=', 'tblservergroupsrel.serverid')
                    ->where('tblservergroupsrel.groupid', $product->servergroup)
                    ->where('tblservers.type', 'gamecp')
                    ->first();
                if ($serverData) {
                    $apiUrl = $serverData->hostname ?: $serverData->ipaddress;
                    $apiKey = $serverData->accesshash;
                }
            }
        }

        // Ensure URL has protocol
        if (!empty($apiUrl) && strpos($apiUrl, 'http') === false) {
            $apiUrl = "https://" . $apiUrl;
        }

        // Get server ID from dedicatedip field
        $serverId = $params['model']->dedicatedip ?? $params['customfields']['GameCP Server ID'] ?? '';
        $clientEmail = $params['clientsdetails']['email'];

        // Determine redirect path
        $redirectPath = !empty($serverId) ? '/game-servers/' . $serverId : '/';

        // Call GameCP SSO token API
        $ssoResponse = gamecp_ApiCall($apiUrl, $apiKey, 'auth/sso-token', 'POST', array(
            'email' => $clientEmail,
            'redirectTo' => $redirectPath,
            'baseUrl' => rtrim($apiUrl, '/') // Send the public URL
        ));

        if ($ssoResponse && isset($ssoResponse['ssoUrl'])) {
            return array(
                'success' => true,
                'redirectTo' => $ssoResponse['ssoUrl'],
            );
        }

        // Fallback: redirect to main panel without auto-login
        return array(
            'success' => true,
            'redirectTo' => rtrim($apiUrl, '/'),
        );

    } catch (Exception $e) {
        logModuleCall(
            'gamecp',
            __FUNCTION__,
            $params,
            $e->getMessage(),
            $e->getTraceAsString(),
            array()
        );

        // Return base URL on error
        $apiUrl = $params['serverhostname'] ?: $params['serverip'];

        // Ensure URL has protocol
        if (!empty($apiUrl) && strpos($apiUrl, 'http') === false) {
            $apiUrl = "https://" . $apiUrl;
        }

        return array(
            'success' => true,
            'redirectTo' => rtrim($apiUrl, '/'),
        );
    }
}

/**
 * Admin Single Sign-On for admin area.
 * Generates a login URL that redirects the admin to GameCP.
 *
 * @param array $params All product/service parameters
 *
 * @return string Login URL
 */
function gamecp_AdminSingleSignOn(array $params)
{
    try {
        $apiUrl = $params['serverhostname'] ?: $params['serverip'];

        // Ensure URL has protocol
        if (!empty($apiUrl) && strpos($apiUrl, 'http') === false) {
            $apiUrl = "https://" . $apiUrl;
        }

        // Admin SSO - redirect to GameCP settings
        $ssoUrl = rtrim($apiUrl, '/') . '/settings';

        return array(
            'success' => true,
            'redirectTo' => $ssoUrl,
        );

    } catch (Exception $e) {
        logModuleCall(
            'gamecp',
            __FUNCTION__,
            $params,
            $e->getMessage(),
            $e->getTraceAsString(),
            array()
        );

        // Return base URL on error
        $apiUrl = $params['serverhostname'] ?: $params['serverip'];


        // Ensure URL has protocol
        if (!empty($apiUrl) && strpos($apiUrl, 'http') === false) {
            $apiUrl = "https://" . $apiUrl;
        }

        $baseUrl = rtrim($apiUrl, '/');

        return array(
            'success' => true,
            'redirectTo' => $baseUrl,
        );
    }
}

/**
 * Save custom field value.
 *
 * @param int $serviceId Service ID
 * @param string $fieldName Field name
 * @param string $fieldValue Field value
 */
function gamecp_SaveCustomField($serviceId, $fieldName, $fieldValue)
{
    try {
        // Use WHMCS LocalAPI to update custom field
        $command = 'UpdateClientProduct';
        $postData = array(
            'serviceid' => $serviceId,
            'customfields' => base64_encode(serialize(array($fieldName => $fieldValue)))
        );

        $results = localAPI($command, $postData);

        if ($results['result'] == 'success') {
            return true;
        }

        // Alternative: Direct database update if LocalAPI doesn't work
        // This requires access to WHMCS database
        // For now, we'll log and return false
        logModuleCall(
            'gamecp',
            'SaveCustomField',
            array('serviceid' => $serviceId, 'fieldname' => $fieldName),
            'Failed to save custom field via LocalAPI',
            $results,
            array()
        );

        return false;

    } catch (Exception $e) {
        logModuleCall(
            'gamecp',
            'SaveCustomField',
            array('serviceid' => $serviceId, 'fieldname' => $fieldName),
            $e->getMessage(),
            $e->getTraceAsString(),
            array()
        );

        return false;
    }
}

