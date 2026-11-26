# How it works

## Overview

```
                       tofu apply
                           │
          ┌────────────────┼────────────────────┐
          ▼                ▼                    ▼
   storage pool      cloud-init ISO        libvirt domain
   base image  ──►   (user-data +    ──►   (VM, hvf or kvm)
   root disks        network-config)            │
                                                ▼
                                   first boot: cloud-init
                                   ├─ static IP on lan0
                                   ├─ /etc/hosts, SSH key
                                   ├─ /etc/rancher/k3s/config.yaml
                                   └─ install k3s (server | agent)
                                                │
                                                ▼
                                   wait-for-cluster.sh
                                   └─ writes infra/kubeconfig
```

## Resources

| Resource | Count | Purpose |
|---|---|---|
| `random_password.k3s_token` | 1 | Shared secret workers use to join |
| `libvirt_pool.cluster` | 1 | Directory pool for all images |
| `libvirt_volume.base` | 1 | Ubuntu cloud image, downloaded once |
| `libvirt_volume.root` | 3 | Copy-on-write disk per node on top of the base image, grown to `disk_gb` |
| `libvirt_network.cluster` | 0–1 | Only with `network_mode = "nat"` |
| `libvirt_cloudinit_disk.init` | 3 | Per-node cloud-init ISO |
| `libvirt_domain.node` | 3 | The VMs |
| `terraform_data.cluster_ready` | 0–1 | Runs `wait-for-cluster.sh` |

The provider is `dmacvicar/libvirt` **0.8.3**. Version 0.9 and later are a complete rewrite with a different schema. Upgrading means rewriting `main.tf`.

## Node addressing

Each node gets a fixed IP and MAC, worked out from `network_cidr` and `ip_offset`:

| Node | IP | MAC |
|---|---|---|
| master | `cidrhost(cidr, ip_offset)` | `52:54:00:6b:38:<ip_offset as hex>` |
| worker-N | `cidrhost(cidr, ip_offset + N)` | `52:54:00:6b:38:<ip_offset+N as hex>` |

Inside the VM, netplan matches the NIC by MAC, renames it to `lan0` and sets the IP statically. k3s uses `lan0` for Flannel (`flannel-iface`) and the node IP. The interface name is the same on every host type.

## k3s setup

cloud-init writes `/etc/rancher/k3s/config.yaml` before running the official installer, so the settings survive upgrades.

**Master** (`k3s server`):

- `node-ip`, `flannel-iface: lan0`
- `tls-san`: master IP and hostname, so the kubeconfig works from outside
- `write-kubeconfig-mode: "0644"`
- taint `node-role.kubernetes.io/control-plane=true:NoSchedule`, unless `master_schedulable = true`

**Workers** (`k3s agent`):

- `server: https://<master-ip>:6443` and the shared token
- the agent retries until the master is up, so no explicit wait is needed

## Dashboard

On the master, cloud-init puts `dashboard.yaml` into `/var/lib/rancher/k3s/server/manifests/`. k3s applies everything in that folder on start:

- a `HelmChart` object: k3s's built-in Helm controller installs the Headlamp chart from `kubernetes-sigs.github.io/headlamp`, with `service.type: NodePort`. No `helm` binary is needed.
- ServiceAccount `dashboard-admin`, a `cluster-admin` ClusterRoleBinding and a long-lived token Secret for logging in

## macOS specifics

libvirt's domain XML is patched with an XSLT stylesheet (`templates/domain.xsl.tftpl`) where provider 0.8.3 has no option:

| Patch | Why |
|---|---|
| cloud-init CD-ROM `ide` → `scsi` | the aarch64 `virt` machine has no IDE bus |
| drop `<pae/>`, `<apic/>` | x86-only features the provider always adds |
| add `<gic version="3"/>` | HVF cannot emulate GICv2, libvirt's default |
| `<qemu:commandline>` with `-netdev stream,…socket_vmnet` and a `virtio-net-pci` NIC at `pcie.0` slot `0x1f` | libvirt has no interface type for socket_vmnet |

Other aarch64 settings come from normal provider options: `machine = "virt"`, UEFI firmware plus a per-VM NVRAM file, and `type = "hvf"`.

On Linux x86_64 no XSLT is applied.
