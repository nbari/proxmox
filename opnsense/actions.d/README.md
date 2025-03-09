## Check https://docs.opnsense.org/development/backend/configd.html

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
