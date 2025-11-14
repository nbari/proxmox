# Update Gateway

This script is used in OPNsense to update the WAN gateway if the current
gateway is not reachable.

Copy the script for example to `/root/gateway-check.php` and create `/root/gateway-check.ini` with:

```
; Gateway Check Configuration
[general]
; UUID for gateway configuration
uuid = 00000000-0000-0000-0000-000000000000
; Interface name
interface = wan
; Gateway IPs to check (in order of priority)
gateway_ips = X.X.X.X, Y.Y.Y.Y, Z.Z.Z.Z
; Ping timeout in seconds
ping_timeout = 1
; Number of ping attempts
ping_count = 3

[logging]
; Enable detailed logging
debug = false
; Log file location
log_file = /var/log/gateway-check.log
; Rotate logs after they reach this size (in bytes), 0 to disable
log_rotate_size = 1048576
; Number of rotated log files to keep
log_rotate_count = 5
```

To get the UUID for the gateway configuration, you can use the API:

```
curl -u "user":"pass" -k -s https://<opnsense/api/routing/settings/get | jq
```

> jq is only needed if you want to pretty print the JSON output.


## Tailscale

To restart tailscale after restarting, create a file in `/usr/local/etc/rc.syshook.d/start` named `99-tailscale` with:

```sh
#!/bin/sh

sleep 10
/usr/local/etc/rc.d/tailscaled restart
```
