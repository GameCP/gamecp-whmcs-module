{*
GameCP Server Provisioning Module - Client Area Template

This template is displayed in the WHMCS client area to show
server status and provide access to the game server.

Available variables:
- serverName: Name of the game server
- serverId: GameCP Server ID
- status: Server status (running, stopped, etc.)
- metrics: Server metrics (CPU, memory, player count, etc.)
- gameStatus: Game-specific status (online players, map, etc.)
- error: Error message if any
- serviceid: WHMCS service ID
*}

{if $error}
    <div class="alert alert-danger">
        <strong>Error:</strong> {$error}
    </div>
{/if}

{if $message}
    <div class="alert {if $message|strpos:'Failed' !== false}alert-danger{else}alert-success{/if}">
        {$message}
    </div>
{/if}

{if !$error}
    <div class="gamecp-client-area">
        <h3>{$serverName}</h3>
        
        <div class="server-status">
            <h4>Server Status</h4>
            <p><strong>Status:</strong> 
                <span class="badge badge-{if $status == 'running'}success{elseif $status == 'stopped'}danger{elseif $status == 'installing'}info{elseif $status == 'configured'}warning{else}warning{/if}">
                    {$status|capitalize}
                </span>
            </p>
            
            {if $gameStatus}
                <p><strong>Online Players:</strong> {$gameStatus.numplayers|default:0} / {$gameStatus.maxplayers|default:0}</p>
                {if $gameStatus.map}
                    <p><strong>Map:</strong> {$gameStatus.map}</p>
                {/if}
            {/if}
        </div>
        
        {if $metrics}
        <div class="server-metrics">
            <h4>Resource Usage</h4>
            {if $metrics.cpuUsage}
                <p><strong>CPU:</strong> {$metrics.cpuUsage|number_format:2}%</p>
            {/if}
            {if $metrics.memoryUsage}
                <p><strong>Memory:</strong> {$metrics.memoryUsage|number_format:2} MB / {$metrics.memoryTotal|number_format:2} MB</p>
            {/if}
            {if $metrics.diskUsage}
                <p><strong>Disk:</strong> {$metrics.diskUsage|number_format:2} MB / {$metrics.diskTotal|number_format:2} MB</p>
            {/if}
        </div>
        {/if}
        
        <div class="server-actions">
            <div class="action-buttons">
                {* Install button - show if server is configured but not installed *}
                {if $status == 'configured' || $status == 'error' || $status == 'aborted'}
                    <form method="post" action="clientarea.php?action=productdetails&id={$serviceid}" style="display: inline;">
                        <input type="hidden" name="gamecp_action" value="install">
                        <button type="submit" class="btn btn-success" onclick="return confirm('Are you sure you want to install this game server? This may take several minutes.');">
                            <i class="fas fa-download"></i> Install Game Server
                        </button>
                    </form>
                {/if}
                
                {* Uninstall button - show if server is installed *}
                {if $status == 'running' || $status == 'stopped' || $status == 'installing'}
                    <form method="post" action="clientarea.php?action=productdetails&id={$serviceid}" style="display: inline; margin-left: 10px;">
                        <input type="hidden" name="gamecp_action" value="uninstall">
                        <button type="submit" class="btn btn-warning" onclick="return confirm('Are you sure you want to uninstall this game server? This will remove all game files but keep the server configuration.');">
                            <i class="fas fa-trash"></i> Uninstall Game Server
                        </button>
                    </form>
                {/if}
                
                {* Login button - always show *}
                <a href="clientarea.php?action=productdetails&id={$serviceid}&gamecp_login=1" class="btn btn-primary" style="margin-left: 10px;">
                    <i class="fas fa-sign-in-alt"></i> Login to GameCP
                </a>
            </div>
        </div>
        
        <div class="server-info" style="margin-top: 20px; padding: 15px; background: #f9f9f9; border-radius: 5px;">
            <p><strong>Server ID:</strong> {$serverId}</p>
            <p><small>Use the buttons above to manage your game server. Installation may take several minutes depending on the game type.</small></p>
        </div>
    </div>
{/if}

<style>
.gamecp-client-area {
    padding: 20px;
}

.server-status, .server-metrics {
    margin: 20px 0;
    padding: 15px;
    background: #f5f5f5;
    border-radius: 5px;
}

.server-status h4, .server-metrics h4 {
    margin-top: 0;
}

.server-actions {
    margin-top: 20px;
}

.action-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
}

.action-buttons .btn {
    white-space: nowrap;
}

.badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: bold;
}

.badge-success {
    background-color: #28a745;
    color: white;
}

.badge-danger {
    background-color: #dc3545;
    color: white;
}

.badge-warning {
    background-color: #ffc107;
    color: #212529;
}

.badge-info {
    background-color: #17a2b8;
    color: white;
}

@media (max-width: 768px) {
    .action-buttons {
        flex-direction: column;
        align-items: stretch;
    }
    
    .action-buttons .btn {
        width: 100%;
        margin-left: 0 !important;
        margin-bottom: 10px;
    }
}
</style>

