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


## change port in LXC container

Stop the ssh.socket service:

```bash
systemctl stop ssh.socket
```

then enable the sshd service:

```bash
systemctl enable ssh
```

finally, reboot

## Containers

List available containers:

```bash
pveam available
```

Download a container:

```bash
pveam download local debian-12-standard_12.7-1_amd64.tar.zst
```
