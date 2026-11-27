# Raspberry Pi 5 host

The Pi 5 is an arm64 machine with KVM, so the cluster can run there like on the Odroid: three Ubuntu VMs with k3s, the platform, and TYPO3 built natively for arm64.

::: warning Not yet tested on hardware
The configuration is prepared and checked without a Pi: the domain XML is rendered and validated against libvirt's schema, and the other hosts are unaffected. Nobody has run it on a real Pi 5 yet, and there is no public report of libvirt/KVM guests on a Pi 5 that confirms it works. Expect to debug the first run.
:::

## What is different on a Pi 5

| Topic | Pi 5 | What the configuration does |
|---|---|---|
| Interrupt controller | **GICv2** (GIC-400). KVM can only give guests the host's GIC; GICv3 guests fail | `gic_version` defaults to `host` on Linux (macOS keeps `3`) |
| UEFI firmware | `qemu-efi-aarch64`: `/usr/share/AAVMF/AAVMF_CODE.fd`, `AAVMF_VARS.fd` | the default on Linux |
| Memory page size | Raspberry Pi OS boots a **16K-page** kernel, listed as incompatible with libvirt/QEMU/KVM | requirement: a 4K-page kernel (see below) |
| vCPUs | 4 cores; GICv2 allows at most 8 vCPUs per VM | 2 vCPUs per VM, shared |
| RAM | 4, 8 or 16 GB | `raspberrypi5.tfvars` is sized for 8 GB |

Sources: [QEMU virt board](https://www.qemu.org/docs/master/system/arm/virt.html) (GICv2, "limits the number of CPUs to 8"), [BCM2712 device tree](https://github.com/raspberrypi/linux/blob/rpi-6.18.y/arch/arm64/boot/dts/broadcom/bcm2712.dtsi) (`arm,gic-400`), [16K-page incompatibilities](https://github.com/raspberrypi/bookworm-feedback/issues/107).

## Hardware

- **Pi 5 with 8 GB** or more. 8 GB is tight: 7 GB go to the VMs. With 16 GB, use the Odroid sizes (`4096` / `6144` MB).
- **NVMe SSD** through an M.2 HAT. Three VM disks plus MariaDB and the image build are too much for an SD card.
- **Active cooling.** Image builds and three VMs keep all four cores busy.

## Prepare the Pi (Ubuntu Server)

Install **Ubuntu Server 24.04 or newer for Raspberry Pi** (64-bit), then:

```sh
getconf PAGESIZE          # must print 4096
ls -l /dev/kvm            # must exist
```

If `PAGESIZE` prints `16384` (Raspberry Pi OS does that by default), switch to the 4K kernel: add `kernel=kernel8.img` to `/boot/firmware/config.txt` and reboot.

Packages and groups:

```sh
sudo apt install -y qemu-system-arm qemu-efi-aarch64 libvirt-daemon-system genisoimage \
  docker.io docker-buildx git jq tmux
sudo usermod -aG libvirt,kvm,docker "$USER"     # log out and back in
ls /usr/share/AAVMF/AAVMF_CODE.fd /usr/share/AAVMF/AAVMF_VARS.fd
```

Also install [OpenTofu](https://opentofu.org/docs/intro/install/), `helm` and `kubectl` (arm64 builds), as on the [Odroid](./odroid).

### Bridge to the home network

So the VMs get LAN addresses (`192.168.178.220`–`.222` in `raspberrypi5.tfvars`), put the Ethernet port into a bridge, e.g. `/etc/netplan/01-br0.yaml`:

```yaml
network:
  version: 2
  ethernets:
    eth0:
      dhcp4: false
  bridges:
    br0:
      interfaces: [eth0]
      dhcp4: true
```

```sh
sudo netplan apply      # over ssh: use `sudo netplan try` and confirm, in case the connection drops
```

Without a bridge, set `network_mode = "nat"` in the tfvars: the VMs are then only reachable from the Pi.

## Create the cluster

On the Pi, as on the Odroid:

```sh
ssh-keygen -t ed25519                       # if the Pi has no key yet: the VMs get it
git clone https://github.com/ochorocho/typo3-kate.git ~/typo3-kate
cd ~/typo3-kate/infra
tofu init
tofu apply -var-file=raspberrypi5.tfvars
cd .. && ./k8s/deploy.sh                    # builds the arm64 TYPO3 image on the Pi
```

The first image build compiles PHP extensions on the Pi: allow a while.

## If the VMs don't start

```sh
virsh -c qemu:///system list --all
sudo journalctl -u libvirtd -n 50
sudo tail -n 30 /var/log/libvirt/qemu/k8s-master.log
```

| Message | Cause |
|---|---|
| `gic-version` / "GICv3 is not supported" | a VM asks for GICv3. Check that `gic_version` isn't set to `3` |
| `kvm_init_vcpu failed`, or the VM hangs at start | 16K-page host kernel: `getconf PAGESIZE` must be 4096 |
| `Unable to find ... AAVMF_CODE.fd` | `qemu-efi-aarch64` missing |
| `Permission denied` on the disk images | AppArmor: see [Troubleshooting](./troubleshooting#ubuntu-host-permission-denied-on-disk-images) |

Please report what works and what doesn't: the page then drops its warning.
