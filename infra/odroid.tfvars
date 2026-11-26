# Odroid H2 (x86_64, KVM). Run tofu on the Odroid itself, or from another
# machine with: libvirt_uri = "qemu+ssh://<user>@<odroid-host>/system"
platform = "linux"
arch     = "x86_64"

# Option A (default): private NAT network, reachable from the Odroid itself.
network_mode = "nat"
network_cidr = "10.17.3.0/24"

# Option B: put the VMs on your LAN through an existing bridge, reachable from
# every machine in the network. Pick free IPs outside your router's DHCP range.
# network_mode = "bridge"
# bridge_name  = "br0"
# network_cidr = "192.168.1.0/24"
# gateway      = "192.168.1.1"
# ip_offset    = 210

# The J4105 has 4 cores; vCPUs are shared, RAM is not (12 GB total here).
master = { vcpu = 2, memory_mb = 4096, disk_gb = 30 }
worker = { vcpu = 2, memory_mb = 4096, disk_gb = 40 }
