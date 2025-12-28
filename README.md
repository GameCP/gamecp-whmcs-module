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

### Debug Logging

The module logs all operations to **WHMCS Module Log**:
- `CreateAccount_Debug` - Full params dump
- `ServerLookup_*` - Database credential lookup
- `ApiCall` - All API requests with curl commands

View logs: **Utilities → Logs → Module Log**


## License

Proprietary - GameCP
