Create the token `metrics`, to be used by the monitoring system:

```
root@pam!metrics
```

Create role `metrics` using as a base the `PVEAuditor` but add `VM.Monitor`

```
pveum role list
```

will show something like:

```
metrics           │ Datastore.Audit,Mapping.Audit,Pool.Audit,Sys.Audit,VM.Audit,VM.Monitor
```


Via cli assign the role to the token:

```bash
pveum acl modify / --roles metrics --tokens 'root@pam!metrics'
```

Check permissions:

```bash
pveum user token permissions root@pam metrics
```
