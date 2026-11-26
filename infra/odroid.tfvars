# Odroid H2 (x86_64, KVM, Debian 12). Run tofu on the Odroid itself:
#   ssh -t <user>@<odroid> tmux new -A -s k8s
#   cd ~/typo3-kate/infra && tofu init && tofu apply -var-file=odroid.tfvars
platform = "linux"
arch     = "x86_64"

# The VMs join the home network through the existing bridge br0, so every
# machine at home (and the router's port forwarding) reaches them directly.
# .210-.212 must be outside the FRITZ!Box DHCP range (default .20-.200).
network_mode = "bridge"
bridge_name  = "br0"
network_cidr = "192.168.178.0/24"
gateway      = "192.168.178.1"
ip_offset    = 210 # master .210, workers .211 and .212

# Without a bridge: private NAT network, reachable only from the Odroid.
# network_mode = "nat"
# network_cidr = "10.17.3.0/24"
# ip_offset    = 10

# The J4105 has 4 cores (vCPUs are shared); 16 GB RAM for the VMs out of 32.
master = { vcpu = 2, memory_mb = 4096, disk_gb = 30 }
worker = { vcpu = 2, memory_mb = 6144, disk_gb = 40 } # room for MariaDB, Valkey and TYPO3

# Let's Encrypt via DNS-01 on Hetzner DNS. While acme_domain is set, every
# tofu apply needs the token: keep it in infra/.env (git-ignored)
#   export TF_VAR_hetzner_dns_token=...
# and run `source .env` before tofu.
acme_domain      = "kate.ochorocho.dev"
acme_environment = "production" # "staging" for test certificates
