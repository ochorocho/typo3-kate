# Odroid H2 host

On the Odroid H2 (Intel J4105, x86_64) the VMs run on KVM through libvirt's system daemon. No patches are needed there: the guests are plain x86_64 Ubuntu machines booting with BIOS.

::: info Not yet tested on hardware
The macOS path has been run end to end. The Odroid path uses the same building blocks without any of the macOS workarounds, but it has not been run on a real Odroid yet.
:::

## Install (Ubuntu or Debian host)

```sh
sudo apt install -y qemu-kvm libvirt-daemon-system genisoimage
sudo usermod -aG libvirt,kvm "$USER"   # then log out and back in
```

`genisoimage` provides `mkisofs`, which builds the cloud-init ISO. Install OpenTofu as described at [opentofu.org](https://opentofu.org/docs/intro/install/).

Check that KVM is available:

```sh
ls -l /dev/kvm
virsh -c qemu:///system list --all
```

## Sizing

The J4105 has 4 cores and takes up to 32 GB RAM. vCPUs are shared between VMs, RAM is not. The defaults use 12 GB for the three VMs, so leave enough for the host.

## Choose a network

### Option A: NAT (default)

```hcl
network_mode = "nat"
network_cidr = "10.17.3.0/24"
```

OpenTofu creates a libvirt network called `k8s`. The nodes get `10.17.3.10`–`.12` and reach the internet through NAT. Only the Odroid itself can reach them, so run `tofu` and `kubectl` on the Odroid.

### Option B: bridge to your LAN

```hcl
network_mode = "bridge"
bridge_name  = "br0"
network_cidr = "192.168.1.0/24"
gateway      = "192.168.1.1"
ip_offset    = 210
```

The VMs join your home network through an existing host bridge, so every machine on the LAN can reach the cluster and the dashboard. Pick an `ip_offset` outside your router's DHCP range.

This needs a bridge (`br0`) on the Odroid. A netplan example:

```yaml
# /etc/netplan/01-br0.yaml
network:
  version: 2
  ethernets:
    enp2s0: {}
  bridges:
    br0:
      interfaces: [enp2s0]
      dhcp4: true
```

## Run from your Mac

You can keep OpenTofu on your Mac and drive the Odroid's libvirt over SSH:

```hcl
libvirt_uri  = "qemu+ssh://<user>@<odroid-host>/system"
network_mode = "bridge"   # so your Mac can reach the VMs
```

The Ubuntu image is then downloaded on your Mac and uploaded to the Odroid. `wait_for_cluster` needs SSH from your Mac to the VMs, which works with the bridge option.

## Apply

```sh
cd infra
tofu init
tofu apply -var-file=odroid.tfvars
```
