# Apple Silicon Mac: QEMU + Hypervisor.framework, networking via socket_vmnet.
platform     = "macos"
arch         = "aarch64"
network_mode = "socket_vmnet"

# socket_vmnet's default vmnet subnet. Its DHCP hands out from .2 upwards,
# so the static node IPs sit high in the range (.200, .201, .202).
network_cidr = "192.168.105.0/24"
gateway      = "192.168.105.1"
ip_offset    = 200

master = { vcpu = 2, memory_mb = 4096, disk_gb = 30 }
worker = { vcpu = 2, memory_mb = 6144, disk_gb = 30 } # room for MariaDB, Valkey and TYPO3

# Domain, Let's Encrypt and the Hetzner DNS token: kate.yaml in the
# repository root (git-ignored, see kate.example.yaml).
