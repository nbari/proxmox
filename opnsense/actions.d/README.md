## Check https://docs.opnsense.org/development/backend/configd.html

> This depends on the type of your gateway, for example if your setups is in
Hetzner using a failover IP it will not work, because the gateway is not
reachable from the firewall.


Copy the files:
- actions_gateway-check.conf
- actions_gateway-check-sleep.conf

Into `/usr/local/opnsense/service/conf/actions.d/`

Then restart configd with:

```
service configd restart
```

Create 2 entries in the cron via OPNsense UI: (settings -> cron):

![cron](cron.png)
