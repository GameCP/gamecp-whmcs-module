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
    <div class="alert {if $message|strpos:'Failed' !== false || $message|strpos:'error' !== false}alert-danger{else}alert-success{/if}">
        {$message}
    </div>
{/if}

{if !$error}
    <div class="gamecp-client-area">
        <div class="gamecp-header">
            <h3>{$serverName}</h3>
            <span class="gamecp-status-badge gamecp-status-{if $status == 'running'}running{elseif $status == 'stopped'}stopped{elseif $status == 'installing'}installing{elseif $status == 'starting'}starting{elseif $status == 'restarting'}restarting{elseif $status == 'configured'}configured{else}unknown{/if}">
                {$status|capitalize}
            </span>
        </div>

        {* Server info bar *}
        {if $gameStatus || $metrics}
        <div class="gamecp-info-bar">
            {if $gameStatus}
                <div class="gamecp-info-item">
                    <span class="gamecp-info-label">Players</span>
                    <span class="gamecp-info-value">{$gameStatus.numplayers|default:0} / {$gameStatus.maxplayers|default:0}</span>
                </div>
                {if $gameStatus.map}
                <div class="gamecp-info-item">
                    <span class="gamecp-info-label">Map</span>
                    <span class="gamecp-info-value">{$gameStatus.map}</span>
                </div>
                {/if}
            {/if}
            {if $metrics.cpuUsage}
                <div class="gamecp-info-item">
                    <span class="gamecp-info-label">CPU</span>
                    <span class="gamecp-info-value">{$metrics.cpuUsage|number_format:1}%</span>
                </div>
            {/if}
            {if $metrics.memoryUsage}
                <div class="gamecp-info-item">
                    <span class="gamecp-info-label">Memory</span>
                    <span class="gamecp-info-value">{$metrics.memoryUsage|number_format:0} MB</span>
                </div>
            {/if}
        </div>
        {/if}

        {* Action buttons *}
        <div class="gamecp-actions">
            {* Go To Panel - always show (SSO login) *}
            <a href="clientarea.php?action=productdetails&id={$serviceid}&gamecp_login=1" class="gamecp-btn gamecp-btn-primary">
                <i class="fas fa-external-link-alt"></i> Go To Panel
            </a>

            {* Start Server - show when stopped *}
            {if $status == 'stopped' || $status == 'error' || $status == 'aborted'}
                <form method="post" action="clientarea.php?action=productdetails&id={$serviceid}" style="display:inline;">
                    <input type="hidden" name="gamecp_action" value="start">
                    <button type="submit" class="gamecp-btn gamecp-btn-action">
                        <i class="fas fa-play"></i> Start Server
                    </button>
                </form>
            {/if}

            {* Stop Server - show when running or starting *}
            {if $status == 'running' || $status == 'starting' || $status == 'restarting'}
                <form method="post" action="clientarea.php?action=productdetails&id={$serviceid}" style="display:inline;">
                    <input type="hidden" name="gamecp_action" value="stop">
                    <button type="submit" class="gamecp-btn gamecp-btn-action">
                        <i class="fas fa-stop"></i> Stop Server
                    </button>
                </form>
            {/if}

            {* Reboot Server - show when running *}
            {if $status == 'running'}
                <form method="post" action="clientarea.php?action=productdetails&id={$serviceid}" style="display:inline;">
                    <input type="hidden" name="gamecp_action" value="restart">
                    <button type="submit" class="gamecp-btn gamecp-btn-action">
                        <i class="fas fa-sync-alt"></i> Reboot Server
                    </button>
                </form>
            {/if}

        </div>

        <div class="gamecp-footer">
            <small>Server ID: {$serverId}</small>
        </div>
    </div>
{/if}

<style>
.gamecp-client-area {
    padding: 20px;
    max-width: 520px;
}

.gamecp-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 16px;
}

.gamecp-header h3 {
    margin: 0;
    font-size: 18px;
    font-weight: 600;
}

.gamecp-status-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.gamecp-status-running {
    background-color: #d4edda;
    color: #155724;
}

.gamecp-status-stopped {
    background-color: #f8d7da;
    color: #721c24;
}

.gamecp-status-installing,
.gamecp-status-starting,
.gamecp-status-restarting {
    background-color: #cce5ff;
    color: #004085;
}

.gamecp-status-configured {
    background-color: #fff3cd;
    color: #856404;
}

.gamecp-status-unknown {
    background-color: #e2e3e5;
    color: #383d41;
}

.gamecp-info-bar {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 20px;
    padding: 12px 16px;
    background: #f8f9fa;
    border-radius: 6px;
    border: 1px solid #e9ecef;
}

.gamecp-info-item {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.gamecp-info-label {
    font-size: 11px;
    font-weight: 600;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.gamecp-info-value {
    font-size: 14px;
    font-weight: 500;
    color: #212529;
}

.gamecp-actions {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-bottom: 20px;
}

.gamecp-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 10px 20px;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    border: none;
    transition: opacity 0.15s, transform 0.1s;
    width: 100%;
    max-width: 260px;
    box-sizing: border-box;
}

.gamecp-btn:hover {
    opacity: 0.85;
    transform: translateY(-1px);
}

.gamecp-btn:active {
    transform: translateY(0);
}

.gamecp-btn-primary {
    background-color: #f0c040;
    color: #1a1a1a;
}

.gamecp-btn-action {
    background-color: #6c757d;
    color: #fff;
}

.gamecp-btn-danger {
    background-color: #6c757d;
    color: #fff;
}

.gamecp-btn-danger:hover {
    background-color: #c0392b;
}

.gamecp-footer {
    color: #adb5bd;
    font-size: 12px;
}

@media (max-width: 768px) {
    .gamecp-btn {
        max-width: 100%;
    }
}
</style>
