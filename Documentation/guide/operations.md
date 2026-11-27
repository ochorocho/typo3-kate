# Day-to-day operations

All commands run in `infra/`, except [Remove a site](#remove-a-site). Replace `macos.tfvars` with `odroid.tfvars` on the Odroid, where `-parallelism=1` isn't needed.

## Destroy everything

```sh
tofu destroy -var-file=macos.tfvars -parallelism=1
```

This removes the VMs, their disks, the downloaded Ubuntu image, the storage pool and, in NAT mode, the libvirt network. It doesn't remove:

- `infra/kubeconfig` (stale afterwards: `rm kubeconfig`)
- the libvirt and socket_vmnet services (`brew services stop libvirt`, `sudo brew services stop socket_vmnet`)

## Remove only the VMs, keep the image

```sh
tofu destroy -var-file=macos.tfvars -parallelism=1 \
  -target=libvirt_volume.root -target=libvirt_cloudinit_disk.init
```

The VMs depend on these disks, so they are destroyed too. The next `tofu apply` rebuilds them without downloading the image again.

## Rebuild from scratch

```sh
tofu apply -var-file=macos.tfvars -parallelism=1 \
  -replace='libvirt_cloudinit_disk.init["k8s-master"]' \
  -replace='libvirt_cloudinit_disk.init["k8s-worker-1"]' \
  -replace='libvirt_cloudinit_disk.init["k8s-worker-2"]'
```

A replaced cloud-init ISO also replaces the node's root disk and the VM, so every node boots fresh.

## Change settings

Edit the `.tfvars` file and run `tofu apply` again.

| Change | Effect |
|---|---|
| `worker_count` up | new workers are created and join automatically. Existing nodes are rebuilt, because every node's `/etc/hosts` lists all nodes |
| `master` / `worker` vCPU or RAM | VMs are recreated in place, with the same disks |
| platform manifests (dashboard, registry, storage, Gateway, Let's Encrypt issuers, operators) | copied to the master over ssh and applied by k3s. **No rebuild**, takes seconds |
| anything that ends up in cloud-init (IPs, k3s settings, SSH key, NFS export, registry mirror) | the affected nodes get new disks: **data on them is lost** |

Check `tofu plan` first: look for `must be replaced`. A second `tofu apply` right after the first must report `No changes`.

## Start and stop VMs

The VMs start automatically with libvirt (`autostart = true`). By hand:

```sh
virsh -c qemu:///session shutdown k8s-worker-2    # macOS
virsh -c qemu:///session start k8s-worker-2
virsh shutdown k8s-worker-2                       # Linux
```

## Log in to a node

```sh
tofu output ssh
ssh ubuntu@192.168.105.201
```

Only key login is allowed (`ssh_public_key_path`, default `~/.ssh/id_ed25519.pub`). The user `ubuntu` can `sudo` without a password.

## Serial console

```sh
virsh -c qemu:///session console k8s-master    # leave with Ctrl+]
```

There is no password login. The console is for watching the boot.

## Upgrade Kubernetes

Set `k3s_channel` (for example `"v1.37"` or `"latest"`) and rebuild, or upgrade in place on each node by re-running the installer:

```sh
ssh ubuntu@<node-ip> 'curl -sfL https://get.k3s.io | INSTALL_K3S_CHANNEL=stable sudo -E sh -s - server'   # master
ssh ubuntu@<node-ip> 'curl -sfL https://get.k3s.io | INSTALL_K3S_CHANNEL=stable sudo -E sh -s - agent'    # workers
```

Upgrade the master first. The settings are kept in `/etc/rancher/k3s/config.yaml`.

## Remove a site

From the repository root:

```sh
./kate remove shop                    # asks you to type the name; --yes skips that
./kate remove shop --delete-volumes   # also deletes fileadmin and typo3temp assets on the NFS server
```

It uninstalls the site's Helm release, deletes its namespace (database, Valkey, Garage, Gateway, certificate, Secrets) and the NFS PersistentVolumes the namespace leaves behind. It only removes a namespace that `./kate deploy` marked as this site's. Left to you:

- the files on the NFS server, unless you chose to delete them: `reclaimPolicy: Retain` keeps them under `/srv/nfs/<pv>` on the master, and the script lists the volumes. On a terminal `./kate remove` asks whether to delete them (default no); `--delete-volumes` says yes up front, and without it `--yes` keeps them. Deleting switches the volumes to `Delete` first, so csi-driver-nfs removes their directories
- the site's DNS record (and router forwarding, if nothing else uses it)
- the folder `projects/<name>/`, if the site is gone for good (otherwise `./kate deploy --all` deploys it again)
