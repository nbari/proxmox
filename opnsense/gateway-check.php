#!/usr/local/bin/php

<?php
/*
 * gateway-check.php
 *
 * Script to check and update the gateway IP based on pingable IPs
 *
 * Usage: php gateway-check.php
 * Run it every 30 seconds via cron to check and update the gateway IP
 *    * * * * * /usr/local/bin/php /root/gateway-check.php
 *    * * * * * sleep 30; /usr/local/bin/php /root/gateway-check.php
 *
 * Options:
 *    -g <gateway_ip>  Manually set the gateway to the specified IP
 *
 *  License BSD-2-Clause
 */

require_once("config.inc");
require_once("interfaces.inc");
require_once("util.inc");
require_once("filter.inc");
require_once("system.inc");
require_once("xmlparse.inc");

/**
 * Loads configuration from the INI file
 *
 * @param string $config_file Path to the INI file
 * @return array Configuration values
 */
function load_config($config_file) {
    if (!file_exists($config_file)) {
        die("Configuration file not found: {$config_file}");
        create_default_config($config_file);
    }

    $config = parse_ini_file($config_file, true);

    if ($config === false) {
        die("Failed to parse configuration file: {$config_file}");
        exit(1);
    }

    // Validate required settings
    $required = ['uuid', 'interface', 'gateway_ips'];
    foreach ($required as $key) {
        if (!isset($config['general'][$key])) {
            die("Missing required configuration: {$key}");
            exit(1);
        }
    }

    // Convert comma-separated IPs to array
    if (is_string($config['general']['gateway_ips'])) {
        $config['general']['gateway_ips'] = array_map('trim',
            explode(',', $config['general']['gateway_ips']));
    }

    return $config;
}

/**
 * Creates a default configuration file
 *
 * @param string $config_file Path to create the INI file
 * @return void
 */
function create_default_config($config_file) {
    $default_config = <<<EOT
; Gateway Check Configuration
[general]
; UUID for gateway configuration
uuid = <replace with your WAN gateway UUID>
; Interface name
interface = wan
; Gateway IPs to check (in order of priority)
gateway_ips = x.x.x.99, x.x.x.100, x.x.x.101, x.x.x.102
; Ping timeout in seconds
ping_timeout = 1
; Number of ping attempts
ping_count = 1

[logging]
; Enable detailed logging
debug = false
; Log file location
log_file = /var/log/gateway-check.log
; Rotate logs after they reach this size (in bytes), 0 to disable
log_rotate_size = 1048576
; Number of rotated log files to keep
log_rotate_count = 5
EOT;

    if (!file_put_contents($config_file, $default_config)) {
        die("Failed to create default configuration file: {$config_file}");
        exit(1);
    }

    die("Created default configuration file: {$config_file}");
}

/**
 * Custom logging function that writes to both system log and custom log file
 *
 * @param string $message Message to log
 * @param array $settings Configuration settings
 * @param bool $is_debug Whether this is a debug message
 * @return void
 */
function gateway_log($message, $settings, $is_debug = false) {
    // Get timestamp
    $timestamp = date('Y-m-d H:i:s');
    $formatted_message = "[GATEWAY-CHECK] {$timestamp}: {$message}";

    // Always log to system log
    log_error($message);

    // Check if we should log this message (debug messages only logged if debug enabled)
    if ($is_debug && (!isset($settings['logging']['debug']) || !$settings['logging']['debug'])) {
        return;
    }

    // Get log file path from settings
    $log_file = isset($settings['logging']['log_file']) ?
        $settings['logging']['log_file'] : '/var/log/gateway-check.log';

    // Check if we need to rotate the log
    rotate_log_if_needed($log_file, $settings);

    // Append to log file
    file_put_contents($log_file, $formatted_message . PHP_EOL, FILE_APPEND);
}

/**
 * Rotates log file if it exceeds configured size
 *
 * @param string $log_file Path to the log file
 * @param array $settings Configuration settings
 * @return void
 */
function rotate_log_if_needed($log_file, $settings) {
    // Get rotation settings
    $rotate_size = isset($settings['logging']['log_rotate_size']) ?
        (int)$settings['logging']['log_rotate_size'] : 1048576; // 1MB default
    $rotate_count = isset($settings['logging']['log_rotate_count']) ?
        (int)$settings['logging']['log_rotate_count'] : 5;

    // Skip if rotation is disabled
    if ($rotate_size <= 0 || !file_exists($log_file)) {
        return;
    }

    // Check current file size
    $file_size = filesize($log_file);
    if ($file_size < $rotate_size) {
        return;
    }

    // Rotate logs
    for ($i = $rotate_count - 1; $i >= 1; $i--) {
        $old_file = "{$log_file}.{$i}";
        $new_file = "{$log_file}." . ($i + 1);
        if (file_exists($old_file)) {
            rename($old_file, $new_file);
        }
    }

    // Move current log to .1
    if (file_exists($log_file)) {
        rename($log_file, "{$log_file}.1");
    }

    // Create new empty log file
    touch($log_file);
    chmod($log_file, 0644);
}

/**
 * Updates the gateway configuration
 *
 * @param string $gateway The gateway IP address to set
 * @param array $settings Configuration settings
 * @return void
 */
function update_gateway($gateway, $settings) {
    global $config;

    if (empty($gateway)) {
        gateway_log("Error: Gateway is not set.", $settings);
        exit(1);
    }

    $uuid = $settings['general']['uuid'];
    $interface = $settings['general']['interface'];
    $item = [
        'interface' => $interface,
        'gateway' => $gateway,
    ];

    try {
        $gw = new \OPNsense\Routing\Gateways();
        $gw->createOrUpdateGateway($item, $uuid);
        $config = OPNsense\Core\Config::getInstance()->toArray(listtags());

        write_config("Updated {$interface} gateway to {$gateway}");
        system_resolver_configure(true);
        interface_reset($interface);
        interface_configure(true, $interface, true);
        filter_configure_sync(true);

        gateway_log("Successfully updated gateway to {$gateway}", $settings);
    } catch (Exception $e) {
        gateway_log("Failed to update gateway: " . $e->getMessage(), $settings);
        exit(1);
    }
}

/**
 * Checks if an IP address is pingable
 *
 * @param string $ip The IP address to ping
 * @param array $settings Configuration settings
 * @return bool True if pingable, false otherwise
 */
function is_pingable($ip, $settings) {
    $ping_count = isset($settings['general']['ping_count']) ? (int)$settings['general']['ping_count'] : 1;
    $ping_timeout = isset($settings['general']['ping_timeout']) ? (int)$settings['general']['ping_timeout'] : 1;

    $cmd = "/sbin/ping -c {$ping_count} -W {$ping_timeout} " . escapeshellarg($ip) . " > /dev/null 2>&1";
    $exit_code = 0;
    system($cmd, $exit_code);

    $result = ($exit_code === 0) ? "SUCCESS" : "FAILED";
    gateway_log("Ping test for {$ip}: {$result}", $settings, true);

    return ($exit_code === 0);
}

/**
 * Gets the current gateway from the global config
 *
 * @param string $uuid UUID of the gateway to find
 * @param array $settings Configuration settings
 * @return string|null Current gateway IP or null if not found
 */
function get_current_gateway($uuid, $settings) {
    global $config;

    // Make sure we have the gateway configuration
    if (!isset($config['OPNsense']['Gateways']['gateway_item']) ||
        !is_array($config['OPNsense']['Gateways']['gateway_item'])) {
        gateway_log("Gateway configuration not found in system config", $settings);
        return null;
    }

    $gateway_items = $config['OPNsense']['Gateways']['gateway_item'];

    // Handle both single gateway and multiple gateways
    if (isset($gateway_items['@attributes'])) {
        // Single gateway case
        if ($gateway_items['@attributes']['uuid'] === $uuid) {
            return isset($gateway_items['gateway']) ? $gateway_items['gateway'] : null;
        }
    } else {
        // Multiple gateways case
        foreach ($gateway_items as $item) {
            if (isset($item['@attributes']['uuid']) && $item['@attributes']['uuid'] === $uuid) {
                return isset($item['gateway']) ? $item['gateway'] : null;
            }
        }
    }

    gateway_log("Gateway with UUID {$uuid} not found in configuration", $settings);
    return null;
}


// First, load configuration
$config_file = '/root/gateway-check.ini';
$settings = load_config($config_file);

// Ensure log file directory exists
$log_file = isset($settings['logging']['log_file']) ?
    $settings['logging']['log_file'] : '/var/log/gateway-check.log';
$log_dir = dirname($log_file);
if (!is_dir($log_dir) && !mkdir($log_dir, 0755, true)) {
    die("Failed to create log directory: {$log_dir}");
}

// Get current gateway from global config
$uuid = $settings['general']['uuid'];
$current_gateway = get_current_gateway($uuid, $settings);
if ($current_gateway === null) {
    gateway_log("Could not determine current gateway from system configuration", $settings);
    exit(1);
}

// Check for manual gateway update via -g
$options = getopt('g:');

if (isset($options['g'])) {
    // Start logging for manual mode
    gateway_log("Manual gateway operation started", $settings);

    // Validate input format
    if (is_array($options['g'])) {
        gateway_log("Error: Multiple -g options provided", $settings);
        fprintf(STDERR, "Error: Multiple -g options provided\n");
        exit(1);
    }

    $new_gateway = trim($options['g']);

    if (!filter_var($new_gateway, FILTER_VALIDATE_IP)) {
        gateway_log("Invalid IP address format: {$new_gateway}", $settings);
        fprintf(STDERR, "Invalid IP address format: {$new_gateway}\n");
        exit(1);
    }

    // Validate against configured gateways if required
    if (!in_array($new_gateway, $settings['general']['gateway_ips'])) {
        gateway_log("Warning: {$new_gateway} not in configured gateway_ips", $settings);
        fprintf(STDERR, "Warning: {$new_gateway} not in configured gateway_ips\n");
        exit(1);
    }

    // Check if the requested gateway is already set
    if ($new_gateway === $current_gateway) {
        gateway_log("Manual gateway update skipped - {$new_gateway} is already the current gateway", $settings);
        echo "Gateway {$new_gateway} is already set as the current gateway. No update needed.\n";
        exit(0);
    }

    // Optionally check if the requested gateway is pingable before setting it
    if (!is_pingable($new_gateway, $settings)) {
        gateway_log("Warning: Manual gateway {$new_gateway} is not pingable", $settings, true);
        // Note: We continue anyway as this is manual override
    }

    gateway_log("Manual gateway override requested: {$new_gateway}", $settings);
    echo "Manual gateway override requested: {$new_gateway}\n";

    update_gateway($new_gateway, $settings);

    gateway_log("Manual gateway update completed", $settings);
    echo "Manual gateway update completed\n";

    exit(0);
}

// Start logging for automatic mode
gateway_log("Gateway check started", $settings);

// Get gateway IPs from config
$gateway_ips = $settings['general']['gateway_ips'];

gateway_log("Current gateway is: {$current_gateway}", $settings, true);
gateway_log("Gateway IPs to check: " . implode(", ", $gateway_ips), $settings, true);

// Check each IP in order until a pingable one is found
$pingable_gateway = null;
foreach ($gateway_ips as $ip) {
    if (is_pingable($ip, $settings)) {
        $pingable_gateway = $ip;
        break;
    }
}

// If no gateway is pingable, exit
if ($pingable_gateway === null) {
    gateway_log("No gateway IPs are pingable. Keeping current gateway.", $settings);
    exit(0);
}

// Only update if the pingable gateway is different from the current one
if ($pingable_gateway !== $current_gateway) {
    gateway_log("Found new pingable gateway {$pingable_gateway}. Current gateway is {$current_gateway}. Updating...", $settings);
    update_gateway($pingable_gateway, $settings);
} else {
    gateway_log("Current gateway {$current_gateway} is already set and pingable. No update needed.", $settings);
}

gateway_log("Gateway check completed", $settings);

exit(0);
