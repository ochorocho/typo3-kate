locals {
  is_macos   = var.platform == "macos"
  is_aarch64 = var.arch == "aarch64"

  # The provider ignores qemu:///session's socket location, so name it explicitly.
  libvirt_uri = coalesce(
    var.libvirt_uri,
    local.is_macos ? "qemu:///session?socket=${pathexpand("~/.cache/libvirt/libvirt-sock")}" : "qemu:///system"
  )
  pool_path = coalesce(
    var.pool_path,
    local.is_macos ? pathexpand("~/.local/share/libvirt/images/${var.cluster_name}") : "/var/lib/libvirt/images/${var.cluster_name}"
  )

  image_arch = local.is_aarch64 ? "arm64" : "amd64"
  image_source = coalesce(
    var.ubuntu_image,
    "https://cloud-images.ubuntu.com/${var.ubuntu_release}/current/${var.ubuntu_release}-server-cloudimg-${local.image_arch}.img"
  )

  prefix_len  = tonumber(split("/", var.network_cidr)[1])
  gateway     = coalesce(var.gateway, cidrhost(var.network_cidr, 1))
  dns_servers = length(var.dns_servers) > 0 ? var.dns_servers : [local.gateway]

  nodes = [
    for i in range(1 + var.worker_count) : {
      role = i == 0 ? "master" : "worker"
      name = i == 0 ? "${var.cluster_name}-master" : "${var.cluster_name}-worker-${i}"
      ip   = cidrhost(var.network_cidr, var.ip_offset + i)
      mac  = format("52:54:00:6b:38:%02x", var.ip_offset + i)
      size = i == 0 ? var.master : var.worker
    }
  ]
  master = local.nodes[0]

  ssh_public_key = trimspace(file(pathexpand(var.ssh_public_key_path)))

  hosts_entries = join("\n", [for n in local.nodes : "${n.ip} ${n.name}"])

  registry = "${local.master.ip}:${var.registry_node_port}"

  # Applied by k3s from /var/lib/rancher/k3s/server/manifests on the master.
  master_manifests = {
    "dashboard.yaml" = templatefile("${path.module}/templates/dashboard.yaml.tftpl", {
      node_port = var.dashboard_node_port
    })
    "registry.yaml" = templatefile("${path.module}/templates/registry.yaml.tftpl", {
      master_ip        = local.master.ip
      registry_port    = var.registry_node_port
      registry_size_gb = var.registry_size_gb
    })
    "storage.yaml" = templatefile("${path.module}/templates/storage.yaml.tftpl", {
      master_ip = local.master.ip
    })
    "gateway.yaml"          = file("${path.module}/templates/gateway.yaml.tftpl")
    "mariadb-operator.yaml" = file("${path.module}/templates/mariadb-operator.yaml.tftpl")
  }
}

resource "random_password" "k3s_token" {
  length  = 48
  special = false
}

# --- Storage -------------------------------------------------------------

resource "libvirt_pool" "cluster" {
  name = var.cluster_name
  type = "dir"

  target {
    path = local.pool_path
  }
}

resource "libvirt_volume" "base" {
  name   = "${var.cluster_name}-ubuntu-${var.ubuntu_release}-${local.image_arch}.qcow2"
  pool   = libvirt_pool.cluster.name
  source = local.image_source
  format = "qcow2"
}

resource "libvirt_volume" "root" {
  for_each = { for n in local.nodes : n.name => n }

  name           = "${each.key}.qcow2"
  pool           = libvirt_pool.cluster.name
  base_volume_id = libvirt_volume.base.id
  format         = "qcow2"
  size           = each.value.size.disk_gb * 1024 * 1024 * 1024

  # cloud-init only runs on first boot, so a changed config needs a fresh disk.
  lifecycle {
    replace_triggered_by = [libvirt_cloudinit_disk.init[each.key]]
  }
}

# --- Network -------------------------------------------------------------

resource "libvirt_network" "cluster" {
  count = var.network_mode == "nat" ? 1 : 0

  name      = var.cluster_name
  mode      = "nat"
  addresses = [var.network_cidr]
  autostart = true

  # IPs are static (set by cloud-init), libvirt only provides NAT and DNS.
  dhcp {
    enabled = false
  }

  dns {
    enabled = true
  }
}

# --- cloud-init ----------------------------------------------------------

resource "libvirt_cloudinit_disk" "init" {
  for_each = { for n in local.nodes : n.name => n }

  name = "${each.key}-cloudinit.iso"
  pool = libvirt_pool.cluster.name

  user_data = templatefile("${path.module}/templates/user-data.yaml.tftpl", {
    hostname           = each.key
    role               = each.value.role
    node_ip            = each.value.ip
    master_ip          = local.master.ip
    ssh_public_key     = local.ssh_public_key
    hosts_entries      = local.hosts_entries
    k3s_token          = random_password.k3s_token.result
    k3s_channel        = var.k3s_channel
    master_schedulable = var.master_schedulable
    manifests          = local.master_manifests
    registry           = local.registry
    nfs_clients        = var.network_cidr
  })

  network_config = templatefile("${path.module}/templates/network-config.yaml.tftpl", {
    mac         = each.value.mac
    address     = "${each.value.ip}/${local.prefix_len}"
    gateway     = local.gateway
    dns_servers = local.dns_servers
  })
}

# --- VMs -----------------------------------------------------------------

resource "libvirt_domain" "node" {
  for_each = { for n in local.nodes : n.name => n }

  name      = each.key
  type      = local.is_macos ? "hvf" : "kvm"
  arch      = var.arch
  machine   = local.is_aarch64 ? "virt" : null
  vcpu      = each.value.size.vcpu
  memory    = each.value.size.memory_mb
  autostart = true
  running   = true

  firmware = local.is_aarch64 ? var.uefi_firmware : null

  dynamic "nvram" {
    for_each = local.is_aarch64 ? [1] : []
    content {
      file     = "${local.pool_path}/${each.key}_VARS.fd"
      template = var.uefi_vars_template
    }
  }

  cpu {
    mode = "host-passthrough"
  }

  cloudinit = libvirt_cloudinit_disk.init[each.key].id

  disk {
    volume_id = libvirt_volume.root[each.key].id
  }

  dynamic "network_interface" {
    for_each = var.network_mode == "nat" ? [1] : []
    content {
      network_id = libvirt_network.cluster[0].id
      mac        = each.value.mac
    }
  }

  dynamic "network_interface" {
    for_each = var.network_mode == "bridge" ? [1] : []
    content {
      bridge = var.bridge_name
      mac    = each.value.mac
    }
  }

  console {
    type        = "pty"
    target_type = "serial"
    target_port = "0"
  }

  # Patches the generated XML where the provider has no option:
  # aarch64 has no IDE bus or x86 features, socket_vmnet needs raw QEMU args.
  dynamic "xml" {
    for_each = local.is_aarch64 || var.network_mode == "socket_vmnet" ? [1] : []
    content {
      xslt = templatefile("${path.module}/templates/domain.xsl.tftpl", {
        aarch64           = local.is_aarch64
        socket_vmnet_path = var.network_mode == "socket_vmnet" ? var.socket_vmnet_path : ""
        mac               = each.value.mac
      })
    }
  }

  lifecycle {
    precondition {
      condition     = var.network_mode != "socket_vmnet" || local.is_macos
      error_message = "network_mode = socket_vmnet only works with platform = macos."
    }
    precondition {
      condition     = !local.is_macos || var.network_mode == "socket_vmnet"
      error_message = "On macOS use network_mode = socket_vmnet (libvirt NAT/bridge networks are Linux only)."
    }
  }
}

# --- Wait for the cluster and fetch kubeconfig ---------------------------

resource "terraform_data" "cluster_ready" {
  count = var.wait_for_cluster ? 1 : 0

  triggers_replace = [for d in libvirt_domain.node : d.id]

  provisioner "local-exec" {
    command = "${path.module}/scripts/wait-for-cluster.sh"
    environment = {
      MASTER_IP      = local.master.ip
      EXPECTED_NODES = length(local.nodes)
      KUBECONFIG_OUT = "${path.module}/kubeconfig"
      REGISTRY       = local.registry
    }
  }
}
