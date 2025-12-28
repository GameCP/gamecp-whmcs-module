# GameCP WHMCS Provisioning Module

This module integrates WHMCS with GameCP to automatically provision game servers when customers purchase hosting plans.

## Features

- **Single Sign-On (SSO)**: Automatic login to the GameCP panel from WHMCS using JWT tokens
- **Live Metrics**: Real-time CPU, Memory, and Player status directly in the WHMCS Client Area
- **Automatic Server Provisioning**: Creates game servers with automatic port assignment when orders are paid
- **User Management**: Automatically creates GameCP users with bcrypt-hashed passwords
- **Server Management**: Full lifecycle support - suspend, unsuspend, and terminate operations
- **Automatic Node Selection**: Intelligently selects optimal nodes or allows manual assignment
- **Server Group Support**: Works with WHMCS server groups for load balancing
- **Flexible Storage**: Server IDs stored in `dedicatedip` field for reliable retrieval
- **API Compatibility**: Supports both ObjectId and serverId for all operations

## Installation

1. **Download the Module**: Download the latest release as a ZIP file from the [GitHub repository](https://github.com/GameCP/gamecp-whmcs-module).

2. **Extract and Upload**: Extract the ZIP file and upload the contents of the `gamecp-whmcs-module` folder to your WHMCS installations server directory:
   ```
   /path/to/whmcs/modules/servers/gamecp/
   ```

3. **Ensure All Files Are Present**: The following files should be present in the directory:
   - `gamecp.php`: Core module logic
   - `clientarea.tpl`: Client area management interface
   - `admin_hook.php`: Admin area hooks and customizations
   - `README.md`: This documentation

4. **Set Permissions**: Ensure the directory and files are readable by your web server (usually `www-data` or `apache`).

## Configuration

### WHMCS Server Configuration

1. Go to **Setup → Products/Services → Servers → Add New Server**
2. Configure the following:
   - **Name**: GameCP Server (or any descriptive name)
   - **Hostname**: Your GameCP domain (e.g., `local.gamecp.com` or `https://app.gamecp.com`)
   - **Type**: Select **"gamecp"** from the dropdown
   - **Username**: Leave blank (not used)
   - **Password**: Leave blank (not used)
   - **Access Hash**: Your GameCP API Key (generate in GameCP Settings → API Keys)

**Important**: The protocol (http/https) is automatically added if not specified.

### WHMCS Product Configuration

1. Go to **Setup → Products/Services → Products/Services → Create New Product**
2. Under the **Module Settings** tab:
   - **Module Name**: Select **"GameCP"**
   - **Server Group**: Select your GameCP server group (recommended) or specific server

3. **Module Configuration Options**:
   - **Game Config ID** (configoption1): The MongoDB ObjectId of the game template in GameCP
   - **Node ID** (configoption2): Optional - specific node ID, or leave empty for automatic selection
   - **Location** (configoption3): Optional - geographic location preference

### How Server Credentials Work

The module uses a **database lookup workaround** to handle WHMCS server groups:

1. When `serverid` is provided, it fetches credentials from `tblservers`
2. If empty, it looks up the product's server group and finds the first GameCP server
3. This ensures API credentials are always available, even with server groups

### Custom Fields (Optional)

You can add custom fields to allow customers to customize their servers:

- **Game Config ID**: Text field for game template selection
- **Node ID**: Text field for manual node selection
- **config_***: Any configuration overrides (prefixed with `config_`)

## GameCP API Setup

### Generating an API Key

1. Log into GameCP as an admin
2. Go to **Settings → API Keys**
3. Click **Create API Key**
4. Copy the generated key (starts with `gcp_`)
5. Paste it into the WHMCS server's **Access Hash** field

### Required API Permissions

The API key needs access to:
- User management (`/api/users`, `/api/settings/users`)
- Game server management (`/api/game-servers`)
- Server control operations (`/api/game-servers/[id]/control`)

## Usage

### Order Flow

1. Customer purchases a GameCP product
2. Payment is processed and order is activated
3. **Module automatically**:
   - Creates/finds user in GameCP with bcrypt password
   - Provisions game server with automatic port assignment
   - Stores server ID in WHMCS `tblhosting.dedicatedip` field
   - Assigns optimal node (or uses specified node)
4. Customer receives server details via email

### Server Management Operations

**Suspend** (Stop Server):
- Sends `POST /api/game-servers/{serverId}/control` with `action: "stop"`
- Server is stopped but not deleted

**Unsuspend** (Start Server):
- Sends `POST /api/game-servers/{serverId}/control` with `action: "start"`
- Server is started again

**Terminate** (Delete Server):
- Sends `DELETE /api/game-servers/{serverId}`
- Server and all data are permanently deleted

## Module Functions

### gamecp_CreateAccount
Creates user and game server when order is activated.

**Process**:
1. Extracts server credentials from database (handles server groups)
2. Creates or finds user via `POST /api/settings/users`
3. Creates game server via `POST /api/game-servers` with `assignmentType: "automatic"`
4. Stores `serverId` in `tblhosting.dedicatedip`

**Returns**: `success` or `error: <message>`

### gamecp_SuspendAccount
Stops the game server.

**API Call**: `POST /api/game-servers/{serverId}/control` with `{"action": "stop"}`

**Returns**: `success` or `error: <message>`

### gamecp_UnsuspendAccount
Starts the game server.

**API Call**: `POST /api/game-servers/{serverId}/control` with `{"action": "start"}`

**Returns**: `success` or `error: <message>`

### gamecp_TerminateAccount
Deletes the game server and all associated data.

**API Call**: `DELETE /api/game-servers/{serverId}`

**Returns**: `success` or `error: <message>`

## API Integration

### Endpoints Used

**User Management**:
- `GET /api/users?email={email}` - Find user by email
- `POST /api/settings/users` - Create new user

**Server Management**:
- `POST /api/game-servers` - Create game server
- `POST /api/game-servers/{serverId}/control` - Control server (start/stop/restart)
- `DELETE /api/game-servers/{serverId}` - Delete server

### Request Format

**Create Game Server**:
```json
{
  "name": "server-1-1234567890",
  "gameId": "694814b0c66143ca5bc74b70",
  "ownerId": "69516c290b99daf65ceaa6ea",
  "nodeId": "694814f6c66143ca5bc74bff",
  "startAfterInstall": true,
  "assignmentType": "automatic",
  "configOverrides": []
}
```

**Control Server**:
```json
{
  "action": "stop"  // or "start", "restart"
}
```

### Response Handling

The module handles both response formats:
- `{"data": {"users": [...]}}` - Standard API wrapper
- `{"users": [...]}` - Direct response
- `{"gameServer": {...}}` - Game server creation
- `{"success": true, ...}` - Operation success

## Troubleshooting

### Common Issues

**"GameCP Server ID not found"**
- Server ID is stored in `dedicatedip` field
- Check `tblhosting` table for the service
- Ensure server was created successfully

**"Invalid auth token"** (Node communication)
- This is a known issue with node server authentication
- Does not affect WHMCS provisioning
- Server creation still works correctly

**Empty API Key**
- Module automatically fetches from database
- Check server is properly configured in WHMCS
- Verify server group has at least one GameCP server

**Port Assignment Errors**
- Module uses `assignmentType: "automatic"` by default
- Ports are auto-assigned even when `nodeId` is specified
- Check node is online and port availability service is running

### Debug Logging

The module logs all operations to **WHMCS Module Log**:
- `CreateAccount_Debug` - Full params dump
- `ServerLookup_*` - Database credential lookup
- `ApiCall` - All API requests with curl commands

View logs: **Utilities → Logs → Module Log**

## Technical Details

### Server ID Storage

Server IDs are stored in `tblhosting.dedicatedip` because:
- Always available (no custom field setup required)
- Reliable retrieval in all module functions
- Survives product configuration changes

### Port Assignment

The module sends `assignmentType: "automatic"` which:
- Auto-assigns ports even when `nodeId` is specified
- Finds available port ranges on the target node
- Handles port conflicts automatically

### Password Generation

User passwords are:
- Generated using `wp_generate_password(16, true, true)`
- Hashed with bcrypt before sending to API
- Stored securely in GameCP database

## Support

For issues or questions:
- Check **WHMCS Module Log** for detailed error messages
- Review [GameCP Documentation](https://docs.gamecp.com)
- Contact GameCP support with log excerpts

## License

Proprietary - GameCP
