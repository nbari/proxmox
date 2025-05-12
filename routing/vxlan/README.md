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
    address fd00:beef::1
    netmask 64
    bridge_ports vxlan42
    bridge_stp off
    bridge_fd 0
    hwaddress ether 02:00:00:00:00:01
    post-up ip link set dev vxlan42 master $IFACE
    # Add router 1
    post-up bridge fdb add 02:00:00:00:00:02 dev vxlan42 dst 2a0c:XXX:YYY::409 self permanent
    post-up bridge fdb append 00:00:00:00:00:00 dev vxlan42 dst 2a0c:XXX:YYY:409
    # Add router 2
    post-up bridge fdb add 02:00:00:00:00:03 dev vxlan42 dst 2001:XXX:YYY::1 self permanent
    post-up bridge fdb append 00:00:00:00:00:00 dev vxlan42 dst 2001:XXX:YYY:1
    post-up sysctl -w net.ipv6.conf.br-beef.proxy_ndp=1
    post-up ip -6 addr add 2001:XXX:YYY:beef::1/64 dev $IFACE
    post up ip -6 route add fd00:beef::/64 dev br-beef table 200
    post-up ip -6 route add default via fd00:beef::feed dev br-beef table 200
    post-up ip -6 rule add from 2001:XXX:YYY:beef::/64 table beef
    pre-down ip -6 rule del from 2001:XXX:YYY:beef::/64 table 200
    pre-down ip -6 route del default via fd00::feed dev br-beef table 200
    down ip link del $IFACE
```

In case need to add more routers use append:

    post-up ip -6 route add default via fd00:beef::a1 dev br-beef table 200
    post-up ip -6 route append default via fd00:beef::a2 dev br-beef table 200
    post-up ip -6 route append default via fd00:beef::a3 dev br-beef table 200

if you do `ip -6 route show table 200` you will see something like:

```
default metric 1024 pref medium
        nexthop via fd00:beef::a1 dev br-beef weight 1
        nexthop via fd00:beef::a2 dev br-beef weight 1
```

This is the `/etc/radvd.conf` in proxmox:

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
    address fd00:beef::feed/64
    bridge_stp off
    bridge_fd 0
    hwaddress ether 02:00:00:00:00:02
    post-up bridge fdb append 02:00:00:00:00:01 dev vxlan42 dst 2a01:XXX:YYY::1 self permanent
    post-up bridge fdb append 00:00:00:00:00:00 dev vxlan42 dst 2a01:XXX:YYY::1
    post-up sysctl -w net.ipv6.conf.br-beef.proxy_ndp=1
    pre-down bridge fdb del 02:00:00:00:00:01 dev vxlan42 dst 2a01:XXX:YYY::1 self
```

for each edge router you need to add a static route in bird.conf, for example:

```
route 2001:XXX:YYY:beef::/64 via "br-beef";
```

The wildcard MAC address is used to allow the EdgeRouter to send packets to the Proxmox host

    post-up bridge fdb append 00:00:00:00:00:00 dev vxlan42 dst 2a01:XXX:YYY::1

> This is only for the first packet, after that the MAC address is learned and the wildcard is not needed anymore. (TODO find better solution)

Radvd configuration is the same as with the wireguard setup.

The Route table in this case instead of using name `beef` we use `200` but this is require to make the route back to the edgerouter, so that ping works from interternet to the proxmox host.
