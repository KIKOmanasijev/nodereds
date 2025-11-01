# Node-RED Hosting Platform

A Laravel-based platform for hosting and managing multiple Node-RED instances on Hetzner VPS servers. Super admins can provision servers, deploy Node-RED instances with Docker, and manage DNS via Cloudflare.

## Features

- **Automated Server Provisioning**: Automatically creates Hetzner servers when needed
- **Smart Capacity Management**: Intelligently distributes instances across servers based on available resources
- **Docker-Based Deployment**: Each Node-RED instance runs in an isolated Docker container
- **Traefik Reverse Proxy**: Automatic SSL certificates via Let's Encrypt (DNS-01 challenge)
- **Cloudflare DNS Integration**: Automatic subdomain creation and DNS record management
- **Super Admin Dashboard**: Complete control panel for managing servers, instances, and plans

## Requirements

- PHP 8.2 or higher
- Composer
- Node.js 18+ and npm
- SQLite (default) or MySQL/PostgreSQL
- SSH access to provisioned servers
- Hetzner Cloud API token
- Cloudflare API token with DNS permissions

## Installation

### 1. Clone and Install Dependencies

```bash
git clone <repository-url> nodereds
cd nodereds
composer install
npm install
```

### 2. Environment Configuration

Copy the example environment file and configure your settings:

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` and configure the following variables:

```env
APP_NAME="Node-RED Hosting"
APP_ENV=local
APP_KEY=base64:...
APP_URL=http://localhost

# Database (SQLite is default)
DB_CONNECTION=sqlite
# Or use MySQL/PostgreSQL:
# DB_CONNECTION=mysql
# DB_HOST=127.0.0.1
# DB_PORT=3306
# DB_DATABASE=nodereds
# DB_USERNAME=root
# DB_PASSWORD=

# Hetzner Cloud Configuration
HETZNER_TOKEN=your_hetzner_api_token
HETZNER_DEFAULT_REGION=nbg1
HETZNER_DEFAULT_IMAGE=ubuntu-24.04
HETZNER_DEFAULT_SERVER_TYPE=cx11

# Cloudflare DNS Configuration
CLOUDFLARE_API_TOKEN=your_cloudflare_api_token
CLOUDFLARE_ZONE_ID=your_cloudflare_zone_id
CLOUDFLARE_PROXIED=false

# DNS Configuration
BASE_DOMAIN=nodereds.com

# SSH Configuration
SSH_PRIVATE_KEY_PATH=storage/keys/provision
SSH_USERNAME=root
SSH_TIMEOUT=30

# Reserved Resources (for OS and Traefik)
RESERVED_MEMORY_MB=512
RESERVED_DISK_GB=10
```

### 3. SSH Key Setup

Generate a new SSH key specifically for this project:

```bash
mkdir -p storage/keys
chmod 700 storage/keys

# Generate a new SSH key pair (don't use a passphrase for automated access)
ssh-keygen -t ed25519 -f storage/keys/provision -C "nodereds-provision" -N ""

# Ensure correct permissions
chmod 600 storage/keys/provision
```

**Important**: This SSH key will be used to connect to all provisioned servers. You need to add the public key to Hetzner Cloud so it's automatically installed on new servers.

#### Add SSH Key to Hetzner Cloud

1. Display your public key:
   ```bash
   cat storage/keys/provision.pub
   ```

2. Go to https://console.hetzner.cloud/
3. Navigate to **Security** → **SSH Keys**
4. Click **Add SSH Key**
5. Give it a name (e.g., "NodeRED Provisioning Key")
6. Paste the public key content
7. Click **Add**

**Note**: The key name you give in Hetzner must match the `HETZNER_SSH_KEY_NAME` in your `.env` file (default is `provision-key`). Update the `.env` if you used a different name:

```env
HETZNER_SSH_KEY_NAME=your-key-name-in-hetzner
```

Alternatively, you can specify the SSH key ID when creating servers programmatically if you prefer.

### 4. Database Setup

If using SQLite (default):

```bash
touch database/database.sqlite
```

Run migrations:

```bash
php artisan migrate
```

### 5. Build Frontend Assets

```bash
npm run build
# Or for development:
npm run dev
```

### 6. Queue Configuration

Configure your queue driver in `.env`:

```env
QUEUE_CONNECTION=database
```

Run migrations again to create the jobs table (if not already done):

```bash
php artisan migrate
```

Start the queue worker:

```bash
php artisan queue:work
```

Or use Laravel Horizon/Sail if preferred.

### 7. Schedule Tasks

The application uses Laravel's task scheduler. Add this to your crontab:

```bash
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

Or use a process manager like Supervisor to run the scheduler.

## Initial Setup

### 1. Create Super Admin User

After running migrations, create a super admin user. You can do this via tinker:

```bash
php artisan tinker
```

```php
$user = App\Models\User::first();
$user->is_super_admin = true;
$user->save();
```

Or directly in the database:

```sql
UPDATE users SET is_super_admin = 1 WHERE id = 1;
```

### 2. Create Initial Plans

Create your first plan via tinker or database:

```php
// Via tinker
App\Models\Plan::create([
    'name' => 'Starter',
    'slug' => 'starter',
    'description' => 'Basic plan for small projects',
    'memory_mb' => 256,
    'storage_gb' => 5,
    'cpu_count' => 1,
    'cpu_limit' => 0.5,
    'monthly_price_cents' => 500,
    'is_active' => true,
    'sort_order' => 1,
]);
```

Or create a seeder:

```bash
php artisan make:seeder PlanSeeder
```

### 3. Access the Admin Dashboard

1. Start the development server:
   ```bash
   php artisan serve
   ```

2. Visit `http://localhost:8000` and log in with your super admin account

3. Navigate to `/admin/servers` to view servers
4. Navigate to `/admin/instances` to manage Node-RED instances
5. Navigate to `/admin/plans` to manage plans

## Usage

### Creating a Node-RED Instance

1. Log in as a super admin
2. Navigate to Admin → Instances → Create
3. Select a user and plan
4. Enter a subdomain (e.g., `my-instance` will create `my-instance.nodereds.com`)
5. Set admin credentials for Node-RED
6. Click "Create Instance"

The system will:
- Find or create an available server
- Provision the server if needed (install Docker, Traefik)
- Deploy the Node-RED container
- Create DNS records via Cloudflare
- Set up SSL certificates automatically

### Server Management

- **View Servers**: `/admin/servers`
- **Server Details**: Click on any server to see instances and resource usage
- Servers are automatically provisioned when needed

### Monitoring

- Server metrics are synced every 5 minutes
- Deployment logs are stored in the `deployments` table
- Check instance status on the instance detail page

## API Configuration

### Hetzner Cloud API Token

1. Go to https://console.hetzner.cloud/
2. Create a new API token
3. Give it read/write permissions
4. Add the token to `.env` as `HETZNER_TOKEN`

### Cloudflare API Token

1. Go to https://dash.cloudflare.com/profile/api-tokens
2. Create a token with:
   - **Zone** → **DNS** → **Edit** permissions
   - **Zone** → **Zone** → **Read** permissions
3. Scope it to your zone (nodereds.com)
4. Add the token to `.env` as `CLOUDFLARE_API_TOKEN`
5. Add your Zone ID to `.env` as `CLOUDFLARE_ZONE_ID` (found in the zone overview)

## Development

### Running Tests

```bash
php artisan test
```

### Code Style

```bash
./vendor/bin/pint
```

### Queue Workers

For development, run the queue worker:

```bash
php artisan queue:work
```

For production, use Supervisor or similar process manager.

### Scheduled Tasks

For development, you can manually run scheduled tasks:

```bash
php artisan schedule:run
```

## Troubleshooting

### SSH Connection Issues

- Ensure the SSH key has correct permissions (`chmod 600`)
- Verify the key is added to Hetzner Cloud SSH keys
- Test SSH connection manually: `ssh -i storage/keys/provision root@<server-ip>`

### DNS Issues

- Verify Cloudflare API token has correct permissions
- Check that Zone ID is correct
- Ensure `BASE_DOMAIN` matches your Cloudflare zone

### Deployment Failures

- Check the deployment logs in the database (`deployments` table)
- Verify server has Docker installed
- Check Traefik logs: `ssh root@<server-ip> "cd /opt/traefik && docker compose logs"`

### Queue Not Processing

- Ensure queue worker is running: `php artisan queue:work`
- Check failed jobs: `php artisan queue:failed`
- Retry failed jobs: `php artisan queue:retry all`

## Security Considerations

1. **SSH Keys**: Never commit SSH private keys to version control
2. **API Tokens**: Keep API tokens secure and rotate them regularly
3. **Super Admin**: Limit super admin access to trusted users only
4. **Firewall**: Ensure servers have proper firewall rules
5. **SSL**: SSL certificates are automatically managed via Traefik

## Production Deployment

1. Set `APP_ENV=production` in `.env`
2. Set `APP_DEBUG=false`
3. Use a production database (MySQL/PostgreSQL recommended)
4. Configure proper queue workers (Supervisor recommended)
5. Set up proper cron for scheduled tasks
6. Use a reverse proxy (Nginx/Caddy) in front of Laravel
7. Enable HTTPS for the Laravel application
8. Set up proper backups for the database

## Architecture

- **Control Plane**: Laravel 12 application
- **Server Provisioning**: Hetzner Cloud API
- **Container Orchestration**: Docker + Docker Compose (via SSH)
- **Reverse Proxy**: Traefik with automatic SSL
- **DNS Management**: Cloudflare DNS API
- **Frontend**: Livewire 4 + Flux UI

## License

[Your License Here]

## Support

For issues and questions, please open an issue on GitHub.

