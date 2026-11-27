# Variables

All variables are defined in `infra/variables.tf`. Set them in `macos.tfvars`, `odroid.tfvars` or with `-var name=value`. Cluster-wide settings in `../kate.yaml` (`acme`, `ssh.authorizedKeys`) win over them; see [your settings](../guide/getting-started#your-settings).

## Host

| Variable | Default | Description |
|---|---|---|
| `platform` | `"linux"` | `linux` (KVM) or `macos` (QEMU + Hypervisor.framework) |
| `arch` | `"x86_64"` | Guest architecture, must match the host: `x86_64` or `aarch64` |
| `libvirt_uri` | `null` | Defaults to `qemu:///system` on Linux and the session socket on macOS. Use `qemu+ssh://user@host/system` for a remote host |
| `pool_path` | `null` | Storage directory. Defaults to `/var/lib/libvirt/images/<cluster>` (Linux) or `~/.local/share/libvirt/images/<cluster>` (macOS) |
| `uefi_firmware` | `null` | UEFI code image for aarch64 guests. Default: Homebrew's `edk2-aarch64-code.fd` on macOS, `/usr/share/AAVMF/AAVMF_CODE.fd` on Linux |
| `uefi_vars_template` | `null` | UEFI variable template for aarch64 guests. Default: Homebrew's `edk2-arm-vars.fd` on macOS, `/usr/share/AAVMF/AAVMF_VARS.fd` on Linux |
| `gic_version` | `null` | Interrupt controller of aarch64 guests: `2`, `3` or `host`. Default: `3` on macOS (HVF only emulates GICv3), `host` on Linux (GICv2 on a Raspberry Pi 5) |

## Cluster

| Variable | Default | Description |
|---|---|---|
| `cluster_name` | `"k8s"` | Prefix for VM names, hostnames, pool and network |
| `worker_count` | `2` | Number of workers (1 master + N workers) |
| `master` | `{ vcpu = 2, memory_mb = 4096, disk_gb = 30 }` | Master size |
| `worker` | `{ vcpu = 2, memory_mb = 4096, disk_gb = 40 }` | Size of each worker. `macos.tfvars` uses 6144 MB, enough for TYPO3 |
| `master_schedulable` | `false` | `true` lets normal workloads run on the master too |
| `k3s_channel` | `"stable"` | k3s release channel (`stable`, `latest`) or a minor such as `v1.37` |
| `ubuntu_release` | `"noble"` | Ubuntu cloud image codename (24.04) |
| `ubuntu_image` | `null` | URL or local path of a cloud image, instead of the download |
| `ssh_public_key_path` | `"~/.ssh/id_ed25519.pub"` | Key installed for the `ubuntu` user |

## Network

| Variable | Default | Description |
|---|---|---|
| `network_mode` | `"nat"` | `nat` (new libvirt NAT network, Linux), `bridge` (existing host bridge, Linux), `socket_vmnet` (macOS) |
| `network_cidr` | `"10.17.3.0/24"` | Subnet of the nodes. Created for `nat`, must match the existing network otherwise |
| `gateway` | `null` | Defaults to the first address of `network_cidr` |
| `dns_servers` | `[]` | Defaults to the gateway |
| `ip_offset` | `10` | Host number of the master. Workers follow: `.10`, `.11`, `.12` |
| `bridge_name` | `"br0"` | Bridge for `network_mode = "bridge"` |
| `socket_vmnet_path` | `/opt/homebrew/var/run/socket_vmnet` | socket_vmnet socket |

Not every combination is allowed: `socket_vmnet` requires `platform = "macos"`, and macOS requires `socket_vmnet`. A wrong combination fails at plan time.

## Dashboard and readiness

| Variable | Default | Description |
|---|---|---|
| `dashboard_node_port` | `30080` | NodePort of Headlamp, on every node |
| `registry_node_port` | `30500` | NodePort of the cluster image registry (plain HTTP, on the master) |
| `registry_size_gb` | `10` | Disk space for the registry |
| `cert_manager_version` | `"v1.21.3"` | cert-manager Helm chart version |
| `acme_email` | `null` | optional contact address for the Let's Encrypt account. `acme.email` in `../kate.yaml` wins |
| `wait_for_cluster` | `true` | After copying the manifests, wait for the dashboard and the platform (registry, NFS driver, mariadb-operator, cert-manager, Let's Encrypt issuers, Gateway) |

`acme_domain` and `acme_environment` are gone: each site sets `domain` and `acme.environment` in `projects/<name>/kate.yaml`, read by `./kate deploy`.

## Outputs

| Output | Example |
|---|---|
| `nodes` | `{ k8s-master = "192.168.105.200", … }` |
| `ssh` | `{ k8s-master = "ssh ubuntu@192.168.105.200", … }` |
| `dashboard_url` | `http://192.168.105.200:30080` |
| `kubeconfig` | `export KUBECONFIG=/…/infra/kubeconfig` |
| `site_url` | `https://192.168.105.200/`: the site on the node IPs. Sites with a domain: their `projects/<name>/kate.yaml` |
| `ca_certificate` | `/…/infra/cluster-ca.crt` |
| `registry` | `192.168.105.200:30500` |
| `dashboard_token_command` | `/…/infra/scripts/dashboard-token.sh 192.168.105.200` |
