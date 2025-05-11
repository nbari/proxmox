# IPv6 only using Vxlan between proxmox and EdgeRouter (bird)

Proxmox configuration:

```
auto vxlan42
iface vxlan42 inet6 manual
    pre-up ip link add $IFACE type vxlan id 42 local 2a01:XXX::YYY::1 dstport 4742 nolearning
    post-up ip link set dev $IFACE mtu 1400
    up ip link set up dev $IFACE
    down ip link del $IFACE

auto br-beef
iface br-beef inet6 static
    address fd00::beef
    netmask 64
    bridge_ports vxlan42
    bridge_stp off
    bridge_fd 0
    hwaddress ether 02:00:00:00:00:01
    post-up ip link set dev vxlan42 master $IFACE
    # Add known remote peers' MAC+IPv6 combos
    post-up bridge fdb add 02:00:00:00:00:02 dev vxlan42 dst 2a0c:XXX:YYY::409 self permanent
    post-up bridge fdb append 00:00:00:00:00:00 dev vxlan42 dst 2a0c:XXX:YYY:409
    post-up sysctl -w net.ipv6.conf.br-beef.proxy_ndp=1
    post-up ip -6 addr add 2001:XXX:YYY:beef::1/64 dev $IFACE
    post-up ip -6 route add default via fd00::feed dev br-beef table 200
    post-up ip -6 rule add from 2001:XXX:YYY:beef::/64 table beef
    pre-down bridge fdb del 02:00:00:00:00:02 dev vxlan42 dst 2a0c:XXX:YYY::409 self
    pre-down ip -6 rule del from 2001:XXX:YYY:beef::/64 table 200
    pre-down ip -6 route del default via fd00::feed dev br-beef table 200
```

EdgeRouter configuration:

```
auto vxlan42
iface vxlan42 inet6 manual
    pre-up ip link add $IFACE type vxlan id 42 dev ens18 local 2a0c:XXX:YYY::409 dstport 4742 nolearning
    post-up ip link set dev $IFACE mtu 1400
    up ip link set $IFACE up
    post-down ip link del $IFACE

auto br-beef
iface br-beef inet6 static
    pre-up [ -d /sys/class/net/$IFACE ] || ip link add name $IFACE type bridge
    pre-up ip link set dev vxlan42 master $IFACE
    address fd00::feed/64
    bridge_stp off
    bridge_fd 0
    hwaddress ether 02:00:00:00:00:02
    post-up bridge fdb append 02:00:00:00:00:01 dev vxlan42 dst 2a01:XXX:YYY::1 self permanent
    post-up bridge fdb append 00:00:00:00:00:00 dev vxlan42 dst 2a01:XXX:YYY::1
    post-up sysctl -w net.ipv6.conf.br-beef.proxy_ndp=1
    pre-down bridge fdb del 02:00:00:00:00:01 dev vxlan42 dst 2a01:XXX:YYY::1 self
```

The wildcard MAC address is used to allow the EdgeRouter to send packets to the Proxmox host

    post-up bridge fdb append 00:00:00:00:00:00 dev vxlan42 dst 2a01:XXX:YYY::1

> This is only for the first packet, after that the MAC address is learned and the wildcard is not needed anymore. (TODO find better solution)

Radvd configuration is the same as with the wireguard setup.

The Route table in this case instead of using name `beef` we use `200` but this is require to make the route back to the edgerouter, so that ping works from interternet to the proxmox host.
