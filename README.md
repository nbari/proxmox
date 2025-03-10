# proxmox
Proxmox ⛑️

## hookscripts

To set a hookscript, use:

```bash
pqm set <VMID> --hookscript local:snippets/<your script>
```

In where `local:snippets/<script>` is the path to the script located in `/var/lib/vz/snippets/<your script>`.

To remove a hookscript, use:

```bash
qm set <VMID> --delete hookscript
```

> qm list will list all the VMs and their respective VMIDs.
