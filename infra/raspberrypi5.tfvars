# Raspberry Pi 5 (aarch64, KVM, Ubuntu Server for Pi). Not yet tested on
# hardware, see Documentation/guide/raspberry-pi5.md. Run tofu on the Pi:
#   cd ~/typo3-kate/infra && tofu init && tofu apply -var-file=raspberrypi5.tfvars
platform = "linux"
arch     = "aarch64"
# gic_version defaults to "host" on Linux, which is GICv2 on the Pi 5.
# UEFI firmware defaults to /usr/share/AAVMF/* (package qemu-efi-aarch64).

# The VMs join the home network through a bridge br0 on the Pi.
# .220-.222 keep clear of the Odroid's .210-.212 and the router's DHCP range.
network_mode = "bridge"
bridge_name  = "br0"
network_cidr = "192.168.178.0/24"
gateway      = "192.168.178.1"
ip_offset    = 220 # master .220, workers .221 and .222

# Without a bridge: private NAT network, reachable only from the Pi.
# network_mode = "nat"
# network_cidr = "10.17.4.0/24"
# ip_offset    = 10

# 8 GB Pi: 7 GB for the VMs, about 1 GB for the host. Tight: TYPO3, MariaDB,
# Valkey and Garage fit, the HPA's extra frontend pods don't. With a 16 GB Pi
# use the Odroid sizes (4096 / 6144 MB). 4 cores: vCPUs are shared.
master = { vcpu = 2, memory_mb = 2048, disk_gb = 30 }
worker = { vcpu = 2, memory_mb = 2560, disk_gb = 30 }
