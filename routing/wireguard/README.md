# IPv6 only VMs with WireGuard on Proxmox and EdgeRouter (Bird)

Install wireguad in Edge Router and proxmox:

> Create pair of keys with `wg genkey | tee privatekey | wg pubkey > publickey`

Configuration of WireGuard on EdgeRouter:

```
[Interface]
PrivateKey = <private key>
Address = fe80::feed/64
ListenPort = 51820
Table = off

[Peer]
PublicKey = <public key>
Endpoint = [2a01:XXX:YYY::1]:51820
AllowedIPs = ::/0
PersistentKeepalive = 25
```

Configuration of WireGuard on Proxmox:

```
[Interface]
PrivateKey = <private key>
Address = fe80::beef/64
ListenPort = 51820
Table = off

[Peer]
PublicKey = <public key>
Endpoint = [2a0c:XXX:YYY::1]:51820
AllowedIPs = ::/0
PersistentKeepalive = 25
```


In the EdgeRouter configure bird to propagate the route:

```
protocol static {
    ipv6;
    route 2001:XXX:YYY::/48 reject;

    route 2001:XXX:YYY:beef::/64 via "wg0";
}

```

In Proxmox and in the EdgeRouter ensure forwarding is enabled:

```
sysctl net.ipv6.conf.all.forwarding=1
```


## Route table

In Proxmox create the `beef` route table for the VM's, in `/etc/iproute2/rt_tables`:

```
#
# reserved values
#
255     local
254     main
253     default
0       unspec
#
# local
#
#1      inr.ruhep
200     beef
```

Then add the following to `/etc/network/interfaces`:

```
auto br-beef
iface br-beef inet6 static
    address 2001:XXX:YYY:beef::1
    netmask 64
    bridge_ports none
    bridge_stp off
    bridge_fd 0
    post-up sysctl -w net.ipv6.conf.br-beef.proxy_ndp=1
    # Create a custom table for VM traffic
    post-up ip -6 route add default via fe80::feed dev wg0 table beef
    post-up ip -6 rule add from 2001:XXX:YYY:beef::/64 table beef
    # Clean-up
    pre-down ip -6 rule del from 2001:XXX:YYY:beef::/64 table beef
    pre-down ip -6 route del default via fe80::feed dev wg0 table beef
```

## Configure radvd

In Proxmox create the file `/etc/radvd.conf`:

```
interface br-beef {
    AdvSendAdvert on;

    prefix 2001:XXX:YYY:beef::/64 {
        AdvOnLink on;
        AdvAutonomous on;
        AdvRouterAddr on;
    };

    RDNSS 2606:4700:4700::1111 2606:4700:4700::1001 {
        AdvRDNSSLifetime 1800;
    };

};
```

Create the VMs using the bridge `br-beef`, they will get an IPv6 address
automatically or you can assign them manually, for example in the range
`2001:XXX:YYY:beef::2` to `2001:XXX:YYY:beef::ff` with a `/64` prefix and
gateway `2001:XXX:YYY:beef::1`. The VMs will be able to reach the internet via
the EdgeRouter and the WireGuard tunnel.
