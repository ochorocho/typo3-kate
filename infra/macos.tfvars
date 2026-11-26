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

# Let's Encrypt via DNS-01 on Hetzner DNS. While acme_domain is set, every
# tofu apply needs the token (Hetzner Console API token, read+write):
#   read -rs TF_VAR_hetzner_dns_token && export TF_VAR_hetzner_dns_token
# or, once it is in the cluster, see Documentation/guide/public-access.md.
acme_domain      = "kate.ochorocho.dev"
acme_environment = "production" # "staging" for test certificates
