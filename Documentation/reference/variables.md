# Variables

All variables are defined in `infra/variables.tf`. Set them in `macos.tfvars`, `odroid.tfvars` or with `-var name=value`.

## Host

| Variable | Default | Description |
|---|---|---|
| `platform` | `"linux"` | `linux` (KVM) or `macos` (QEMU + Hypervisor.framework) |
| `arch` | `"x86_64"` | Guest architecture, must match the host: `x86_64` or `aarch64` |
| `libvirt_uri` | `null` | Defaults to `qemu:///system` on Linux and the session socket on macOS. Use `qemu+ssh://user@host/system` for a remote host |
| `pool_path` | `null` | Storage directory. Defaults to `/var/lib/libvirt/images/<cluster>` (Linux) or `~/.local/share/libvirt/images/<cluster>` (macOS) |
| `uefi_firmware` | `/opt/homebrew/share/qemu/edk2-aarch64-code.fd` | UEFI code image, only used for aarch64 |
| `uefi_vars_template` | `/opt/homebrew/share/qemu/edk2-arm-vars.fd` | UEFI variable template, only used for aarch64 |

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
| `wait_for_cluster` | `true` | Wait for all nodes, the dashboard and the platform (registry, NFS driver, mariadb-operator, Gateway), then write `infra/kubeconfig`. Needs SSH from the machine running `tofu` to the master |

## Outputs

| Output | Example |
|---|---|
| `nodes` | `{ k8s-master = "192.168.105.200", … }` |
| `ssh` | `{ k8s-master = "ssh ubuntu@192.168.105.200", … }` |
| `dashboard_url` | `http://192.168.105.200:30080` |
| `kubeconfig` | `export KUBECONFIG=/…/infra/kubeconfig` |
| `site_url` | `https://192.168.105.200/` |
| `ca_certificate` | `/…/infra/cluster-ca.crt` |
| `registry` | `192.168.105.200:30500` |
| `dashboard_token_command` | `/…/infra/scripts/dashboard-token.sh 192.168.105.200` |
