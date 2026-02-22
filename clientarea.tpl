{*
GameCP Client Area Template

Variables:
- serverName, serverId, status, metrics, gameStatus,
  connectionAddress, error, serviceid, message
*}

{if $message}
    <div class="gamecp-alert {if $message|strpos:'Failed' !== false || $message|strpos:'error' !== false}gamecp-alert-error{else}gamecp-alert-success{/if}">
        <span class="gamecp-alert-icon">{if $message|strpos:'Failed' !== false || $message|strpos:'error' !== false}<i class="fas fa-exclamation-circle"></i>{else}<i class="fas fa-check-circle"></i>{/if}</span>
        {$message}
    </div>
{/if}

{if $error}
    <div class="gamecp-alert gamecp-alert-error">
        <span class="gamecp-alert-icon"><i class="fas fa-exclamation-circle"></i></span>
        {$error}
    </div>
{/if}

{if !$error}
<div class="gamecp-panel">
    {* Header *}
    <div class="gamecp-header">
        <div class="gamecp-header-left">
            <h3>{$serverName}</h3>
            <span class="gamecp-badge gamecp-badge-{if $status == 'running'}success{elseif $status == 'stopped' || $status == 'error' || $status == 'aborted'}danger{elseif $status == 'installing' || $status == 'starting' || $status == 'restarting'}info{elseif $status == 'configured'}warning{else}muted{/if}">
                <span class="gamecp-badge-dot"></span>
                {$status|capitalize}
            </span>
        </div>
        <a href="clientarea.php?action=productdetails&id={$serviceid}&gamecp_login=1" class="gamecp-btn-panel">
            <i class="fas fa-external-link-alt"></i> Open Panel
        </a>
    </div>

    {* Connection Address (prominent, full-width) *}
    {if $connectionAddress}
    <div class="gamecp-connect" onclick="navigator.clipboard.writeText('{$connectionAddress}'); var el=this; el.classList.add('copied'); setTimeout(function(){ el.classList.remove('copied') }, 1500)" title="Click to copy">
        <div class="gamecp-connect-label">Connection Address</div>
        <div class="gamecp-connect-value">{$connectionAddress}</div>
        <span class="gamecp-connect-action"><i class="fas fa-copy"></i> <span class="gamecp-connect-action-text">Click to copy</span></span>
    </div>
    {/if}

    {* Stats Grid *}
    <div class="gamecp-stats">
        {if $metrics.playerCount !== null || $gameStatus}
        <div class="gamecp-stat">
            <div class="gamecp-stat-label">Players</div>
            <div class="gamecp-stat-value">{$metrics.playerCount|default:$gameStatus.numplayers|default:0}<span class="gamecp-stat-dim"> / {$metrics.maxPlayers|default:$gameStatus.maxplayers|default:0}</span></div>
        </div>
        {/if}
        {if $metrics.cpuUsage}
        <div class="gamecp-stat">
            <div class="gamecp-stat-label">CPU</div>
            <div class="gamecp-stat-value">{$metrics.cpuUsage|number_format:1}<span class="gamecp-stat-dim">%</span></div>
        </div>
        {/if}
        {if $metrics.memoryUsage}
        <div class="gamecp-stat">
            <div class="gamecp-stat-label">Memory</div>
            <div class="gamecp-stat-value">{$metrics.memoryUsage|number_format:0}<span class="gamecp-stat-dim"> MB</span></div>
        </div>
        {/if}
        {if $metrics.diskUsage && $metrics.diskUsage > 0}
        <div class="gamecp-stat">
            <div class="gamecp-stat-label">Disk</div>
            <div class="gamecp-stat-value">{if $metrics.diskUsage > 1024}{($metrics.diskUsage / 1024)|number_format:1}<span class="gamecp-stat-dim"> GB</span>{else}{$metrics.diskUsage|number_format:0}<span class="gamecp-stat-dim"> MB</span>{/if}</div>
        </div>
        {/if}
        {if $metrics.uptime && $metrics.uptime > 0}
        <div class="gamecp-stat">
            <div class="gamecp-stat-label">Uptime</div>
            <div class="gamecp-stat-value">
                {if $metrics.uptime >= 86400}
                    {($metrics.uptime / 86400)|number_format:0}<span class="gamecp-stat-dim">d </span>{(($metrics.uptime % 86400) / 3600)|number_format:0}<span class="gamecp-stat-dim">h</span>
                {elseif $metrics.uptime >= 3600}
                    {($metrics.uptime / 3600)|number_format:0}<span class="gamecp-stat-dim">h </span>{(($metrics.uptime % 3600) / 60)|number_format:0}<span class="gamecp-stat-dim">m</span>
                {else}
                    {($metrics.uptime / 60)|number_format:0}<span class="gamecp-stat-dim">m</span>
                {/if}
            </div>
        </div>
        {/if}
        {if $gameStatus.map}
        <div class="gamecp-stat">
            <div class="gamecp-stat-label">Map</div>
            <div class="gamecp-stat-value gamecp-stat-text">{$gameStatus.map}</div>
        </div>
        {/if}
    </div>

    {* Controls *}
    <div class="gamecp-controls">
        {if $status == 'stopped' || $status == 'error' || $status == 'aborted'}
            <form method="post" action="clientarea.php?action=productdetails&id={$serviceid}">
                <input type="hidden" name="gamecp_action" value="start">
                <button type="submit" class="gamecp-btn gamecp-btn-success">
                    <i class="fas fa-play"></i> Start
                </button>
            </form>
        {/if}

        {if $status == 'running' || $status == 'starting' || $status == 'restarting'}
            <form method="post" action="clientarea.php?action=productdetails&id={$serviceid}">
                <input type="hidden" name="gamecp_action" value="restart">
                <button type="submit" class="gamecp-btn gamecp-btn-default">
                    <i class="fas fa-sync-alt"></i> Restart
                </button>
            </form>
            <form method="post" action="clientarea.php?action=productdetails&id={$serviceid}">
                <input type="hidden" name="gamecp_action" value="stop">
                <button type="submit" class="gamecp-btn gamecp-btn-danger">
                    <i class="fas fa-stop"></i> Stop
                </button>
            </form>
        {/if}

        {if $status == 'installing'}
            <div class="gamecp-installing-hint">
                <i class="fas fa-spinner fa-spin"></i> Server is installing. This page will update on refresh.
            </div>
        {/if}
    </div>

    {* Footer *}
    <div class="gamecp-footer">
        <span>{$serverId}</span>
        <span>Powered by <strong>GameCP</strong></span>
    </div>
</div>
{/if}

<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');

.gamecp-panel {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
}

/* Header */
.gamecp-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 0;
    margin-bottom: 16px;
    border-bottom: 1px solid #e5e7eb;
}

.gamecp-header-left {
    display: flex;
    align-items: center;
    gap: 10px;
}

.gamecp-header h3 {
    margin: 0;
    font-size: 17px;
    font-weight: 700;
    color: #111827;
}

/* Badge */
.gamecp-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.4px;
}

.gamecp-badge-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    display: inline-block;
}

.gamecp-badge-success     { background: #ecfdf5; color: #059669; }
.gamecp-badge-success .gamecp-badge-dot { background: #10b981; }
.gamecp-badge-danger      { background: #fef2f2; color: #dc2626; }
.gamecp-badge-danger .gamecp-badge-dot  { background: #ef4444; }
.gamecp-badge-info        { background: #eff6ff; color: #2563eb; }
.gamecp-badge-info .gamecp-badge-dot    { background: #3b82f6; animation: gamecp-pulse 1.5s ease-in-out infinite; }
.gamecp-badge-warning     { background: #fffbeb; color: #d97706; }
.gamecp-badge-warning .gamecp-badge-dot { background: #f59e0b; }
.gamecp-badge-muted       { background: #f3f4f6; color: #6b7280; }
.gamecp-badge-muted .gamecp-badge-dot   { background: #9ca3af; }

@keyframes gamecp-pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.4; }
}

/* Open Panel Button */
.gamecp-btn-panel {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 14px;
    background: #111827;
    color: #fff !important;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    text-decoration: none !important;
    transition: background 0.15s;
}
.gamecp-btn-panel:hover { background: #1f2937; color: #fff !important; text-decoration: none !important; }

/* Connection Address */
.gamecp-connect {
    padding: 16px 20px;
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    margin-bottom: 16px;
    cursor: pointer;
    transition: background 0.15s, border-color 0.15s;
}
.gamecp-connect:hover { background: #f3f4f6; border-color: #d1d5db; }
.gamecp-connect.copied { border-color: #10b981; }
.gamecp-connect.copied .gamecp-connect-action-text { display: none; }
.gamecp-connect.copied .gamecp-connect-action::after { content: 'Copied!'; color: #059669; font-weight: 600; }

.gamecp-connect-label {
    font-size: 10px;
    font-weight: 600;
    color: #9ca3af;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 4px;
}

.gamecp-connect-value {
    font-family: 'SF Mono', 'Fira Code', 'Cascadia Code', monospace;
    font-size: 18px;
    font-weight: 700;
    color: #111827;
    letter-spacing: -0.3px;
}

.gamecp-connect-action {
    font-size: 11px;
    color: #9ca3af;
    margin-top: 4px;
    display: block;
}

/* Stats Grid */
.gamecp-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
    gap: 0;
    margin-bottom: 16px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    overflow: hidden;
}

.gamecp-stat {
    padding: 14px 16px;
    background: #fff;
    border-right: 1px solid #f3f4f6;
    border-bottom: 1px solid #f3f4f6;
}
.gamecp-stat:last-child { border-right: none; }

.gamecp-stat-label {
    font-size: 10px;
    font-weight: 600;
    color: #9ca3af;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 4px;
}

.gamecp-stat-value {
    font-size: 20px;
    font-weight: 700;
    color: #111827;
    white-space: nowrap;
}

.gamecp-stat-text {
    font-size: 14px;
    font-weight: 600;
}

.gamecp-stat-dim {
    font-weight: 500;
    color: #9ca3af;
    font-size: 14px;
}

/* Controls */
.gamecp-controls {
    display: flex;
    gap: 8px;
    padding: 16px 0;
}

.gamecp-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    font-family: inherit;
    border: 1px solid transparent;
    cursor: pointer;
    transition: all 0.15s;
}

.gamecp-btn-success { background: #ecfdf5; color: #059669; border-color: #d1fae5; }
.gamecp-btn-success:hover { background: #059669; color: #fff; }
.gamecp-btn-default { background: #f3f4f6; color: #374151; border-color: #e5e7eb; }
.gamecp-btn-default:hover { background: #e5e7eb; }
.gamecp-btn-danger { background: #fef2f2; color: #dc2626; border-color: #fecaca; }
.gamecp-btn-danger:hover { background: #dc2626; color: #fff; }

.gamecp-installing-hint {
    font-size: 13px;
    color: #6b7280;
    display: flex;
    align-items: center;
    gap: 8px;
}

/* Footer */
.gamecp-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 0;
    border-top: 1px solid #f3f4f6;
    font-size: 11px;
    color: #d1d5db;
}
.gamecp-footer strong { color: #9ca3af; }

/* Alerts */
.gamecp-alert {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 16px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 500;
    margin-bottom: 16px;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
}
.gamecp-alert-success { background: #ecfdf5; color: #065f46; border: 1px solid #d1fae5; }
.gamecp-alert-error   { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.gamecp-alert-icon    { font-size: 16px; flex-shrink: 0; }

@media (max-width: 480px) {
    .gamecp-header { flex-direction: column; align-items: flex-start; gap: 12px; }
    .gamecp-stats { grid-template-columns: 1fr 1fr; }
    .gamecp-controls { flex-wrap: wrap; }
}
</style>

<script>
document.querySelectorAll('.gamecp-controls form').forEach(function(form) {
    form.addEventListener('submit', function() {
        var btn = form.querySelector('button');
        if (btn) {
            btn.disabled = true;
            btn.style.opacity = '0.5';
            btn.style.pointerEvents = 'none';
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
        }
    });
});
</script>
