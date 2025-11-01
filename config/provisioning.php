<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Provisioning Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for server provisioning, DNS, and deployment services.
    |
    */

    'hetzner' => [
        'token' => env('HETZNER_TOKEN'),
        'default_region' => env('HETZNER_DEFAULT_REGION', 'nbg1'),
        'default_image' => env('HETZNER_DEFAULT_IMAGE', 'ubuntu-24.04'),
        'default_server_type' => env('HETZNER_DEFAULT_SERVER_TYPE', 'cpx11'), // 2 vCPU, 2GB RAM (cx11 is deprecated)
        'ssh_key_name' => env('HETZNER_SSH_KEY_NAME', 'provision-key'),
    ],

    'cloudflare' => [
        'api_token' => env('CLOUDFLARE_API_TOKEN'),
        'zone_id' => env('CLOUDFLARE_ZONE_ID'),
        'proxied' => env('CLOUDFLARE_PROXIED', false),
    ],

    'dns' => [
        'base_domain' => env('BASE_DOMAIN', 'nodereds.com'),
    ],

    'ssh' => [
        'private_key_path' => env('SSH_PRIVATE_KEY_PATH', storage_path('keys/provision')),
        'username' => env('SSH_USERNAME', 'root'),
        'timeout' => env('SSH_TIMEOUT', 30),
    ],

    'docker' => [
        'traefik_path' => '/opt/traefik',
        'nodered_path' => '/opt/nodered',
        'network_name' => 'edge',
    ],

    'reserved_resources' => [
        'memory_mb' => env('RESERVED_MEMORY_MB', 512), // Reserve for OS and Traefik
        'disk_gb' => env('RESERVED_DISK_GB', 10), // Reserve for OS
    ],

    'testing' => [
        'reuse_existing_servers' => env('REUSE_EXISTING_SERVERS', false), // For testing: always reuse existing servers
        'hardcoded_server_id' => env('HARDCODED_SERVER_ID', null), // For testing: always use this server ID
    ],

];

