# macOS host

macOS has no KVM. libvirt runs the VMs with QEMU and Apple's Hypervisor.framework (domain type `hvf`), which runs guests at close to native speed. On Apple Silicon the guests are arm64 Ubuntu.

Tested with macOS 26, libvirt 12.9, QEMU 11.1 and socket_vmnet 1.2.2.

## Install

```sh
brew install opentofu libvirt qemu socket_vmnet cdrtools
```

| Package | Why |
|---|---|
| `opentofu` | runs the configuration |
| `libvirt`, `qemu` | create and run the VMs |
| `socket_vmnet` | network for the VMs (see below) |
| `cdrtools` | provides `mkisofs`, which builds the cloud-init ISO |

## Start the services

```sh
brew services start libvirt               # runs as your user
sudo brew services start socket_vmnet     # needs root for Apple's vmnet
```

Run the `sudo` line in a normal terminal window, because it asks for your password.

Check that both are up:

```sh
ls -l ~/.cache/libvirt/libvirt-sock            # libvirt session socket
ls -l /opt/homebrew/var/run/socket_vmnet       # owned by root, group staff
```

## Networking

libvirt's own networks (NAT, bridge) only work on Linux. On macOS the VMs connect to **socket_vmnet**, a small root daemon around Apple's `vmnet` framework. QEMU talks to its socket directly. The result:

- the VMs reach each other and the internet
- your Mac reaches the VMs directly, with no port forwarding
- the subnet is `192.168.105.0/24` with the gateway and DNS at `192.168.105.1`

The nodes use static IPs high in that range (`.200`, `.201`, `.202`), away from the addresses socket_vmnet's DHCP hands out from `.2` upwards.

## Allow local network access

macOS blocks apps from reaching local subnets until you allow it. The error looks like a network problem (`no route to host`), but it is a permission. Apple's own tools (`ssh`, `ping`, `/usr/bin/curl`) are exempt, which makes this confusing: SSH works, but `kubectl` doesn't.

Open **System Settings → Privacy & Security → Local Network** and enable:

- your terminal app (Terminal, iTerm, …), so `kubectl` works
- your browser, so the dashboard opens

## Settings file

`infra/macos.tfvars`:

```hcl
platform     = "macos"
arch         = "aarch64"
network_mode = "socket_vmnet"

network_cidr = "192.168.105.0/24"
gateway      = "192.168.105.1"
ip_offset    = 200

master = { vcpu = 2, memory_mb = 4096, disk_gb = 30 }
worker = { vcpu = 2, memory_mb = 4096, disk_gb = 30 }
```

On an **Intel Mac**, set `arch = "x86_64"`. The aarch64-specific patches (UEFI, GICv3, SCSI CD-ROM) then switch off automatically. This combination has not been tested.

## Where things live

| What | Path |
|---|---|
| Disk images, cloud-init ISOs, UEFI vars | `~/.local/share/libvirt/images/k8s/` |
| QEMU logs | `~/.cache/libvirt/qemu/log/<vm>.log` |
| socket_vmnet logs | `/opt/homebrew/var/log/socket_vmnet/` |
| libvirt URI | `qemu:///session?socket=~/.cache/libvirt/libvirt-sock` |

```sh
virsh -c qemu:///session list --all
```
