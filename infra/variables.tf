variable "platform" {
  description = "Host type: \"linux\" (KVM, e.g. Odroid H2) or \"macos\" (QEMU with Hypervisor.framework)."
  type        = string
  default     = "linux"

  validation {
    condition     = contains(["linux", "macos"], var.platform)
    error_message = "platform must be \"linux\" or \"macos\"."
  }
}

variable "arch" {
  description = "Guest CPU architecture. Must match the host: x86_64 for the Odroid H2 and Intel Macs, aarch64 for Apple Silicon."
  type        = string
  default     = "x86_64"

  validation {
    condition     = contains(["x86_64", "aarch64"], var.arch)
    error_message = "arch must be \"x86_64\" or \"aarch64\"."
  }
}

variable "libvirt_uri" {
  description = "libvirt connection URI. Defaults to qemu:///system on Linux and qemu:///session on macOS. Use qemu+ssh://user@odroid/system to drive a remote host."
  type        = string
  default     = null
}

variable "cluster_name" {
  description = "Prefix for all libvirt objects and hostnames."
  type        = string
  default     = "k8s"
}

variable "worker_count" {
  description = "Number of worker nodes (1 master + N workers)."
  type        = number
  default     = 2
}

variable "master" {
  description = "Master node size."
  type = object({
    vcpu      = number
    memory_mb = number
    disk_gb   = number
  })
  default = { vcpu = 2, memory_mb = 4096, disk_gb = 30 }
}

variable "worker" {
  description = "Worker node size."
  type = object({
    vcpu      = number
    memory_mb = number
    disk_gb   = number
  })
  default = { vcpu = 2, memory_mb = 4096, disk_gb = 40 }
}

variable "master_schedulable" {
  description = "Allow normal workloads on the master. false taints it so apps run on the workers only."
  type        = bool
  default     = false
}

variable "ubuntu_release" {
  description = "Ubuntu cloud image codename."
  type        = string
  default     = "noble"
}

variable "ubuntu_image" {
  description = "Override the cloud image URL or local path (e.g. a pre-downloaded .img)."
  type        = string
  default     = null
}

variable "pool_path" {
  description = "Directory for the libvirt storage pool. Defaults to /var/lib/libvirt/images/<cluster> on Linux and ~/.local/share/libvirt/images/<cluster> on macOS."
  type        = string
  default     = null
}

variable "network_mode" {
  description = <<-EOT
    How the VMs are networked:
      nat          - new libvirt NAT network (Linux). Host reaches the VMs directly.
      bridge       - attach to an existing host bridge (Linux), VMs get LAN IPs.
      socket_vmnet - Apple vmnet via socket_vmnet (macOS). Host reaches the VMs directly.
  EOT
  type        = string
  default     = "nat"

  validation {
    condition     = contains(["nat", "bridge", "socket_vmnet"], var.network_mode)
    error_message = "network_mode must be nat, bridge or socket_vmnet."
  }
}

variable "network_cidr" {
  description = "Subnet the nodes live in. For nat it is created; for bridge/socket_vmnet it must match the existing network."
  type        = string
  default     = "10.17.3.0/24"
}

variable "gateway" {
  description = "Default gateway. Defaults to the first host address of network_cidr."
  type        = string
  default     = null
}

variable "dns_servers" {
  description = "DNS servers for the nodes. Defaults to the gateway."
  type        = list(string)
  default     = []
}

variable "ip_offset" {
  description = "Host number of the master in network_cidr; workers follow. 10 gives .10, .11, .12."
  type        = number
  default     = 10
}

variable "bridge_name" {
  description = "Host bridge for network_mode = bridge."
  type        = string
  default     = "br0"
}

variable "socket_vmnet_path" {
  description = "socket_vmnet socket for network_mode = socket_vmnet."
  type        = string
  default     = "/opt/homebrew/var/run/socket_vmnet"
}

variable "uefi_firmware" {
  description = "UEFI code image, needed for aarch64 guests."
  type        = string
  default     = "/opt/homebrew/share/qemu/edk2-aarch64-code.fd"
}

variable "uefi_vars_template" {
  description = "UEFI variable store template, needed for aarch64 guests."
  type        = string
  default     = "/opt/homebrew/share/qemu/edk2-arm-vars.fd"
}

variable "ssh_public_key_path" {
  description = "Public key installed for the ubuntu user."
  type        = string
  default     = "~/.ssh/id_ed25519.pub"
}

variable "k3s_channel" {
  description = "k3s release channel (stable, latest) or a minor like v1.34."
  type        = string
  default     = "stable"
}

variable "dashboard_node_port" {
  description = "NodePort the Headlamp dashboard listens on, on every node."
  type        = number
  default     = 30080
}

variable "registry_node_port" {
  description = "NodePort of the cluster image registry (plain HTTP, on the master)."
  type        = number
  default     = 30500
}

variable "registry_size_gb" {
  description = "Disk space for the image registry."
  type        = number
  default     = 10
}

variable "cert_manager_version" {
  description = "cert-manager Helm chart version."
  type        = string
  default     = "v1.21.3"
}

variable "acme_domain" {
  description = "Public hostname for a Let's Encrypt certificate on the Gateway, e.g. kate.ochorocho.dev. Needs hetzner_dns_token. null = IP access with the cluster CA only."
  type        = string
  default     = null
}

variable "acme_environment" {
  description = "Let's Encrypt environment: staging (untrusted test certificates, generous limits) or production."
  type        = string
  default     = "staging"

  validation {
    condition     = contains(["staging", "production"], var.acme_environment)
    error_message = "acme_environment must be staging or production."
  }
}

variable "acme_email" {
  description = "Optional contact address for the Let's Encrypt account."
  type        = string
  default     = null
}

variable "hetzner_dns_token" {
  description = "Hetzner Console API token (read+write) for DNS-01. Pass it as TF_VAR_hetzner_dns_token, never in a committed file."
  type        = string
  default     = null
  sensitive   = true
}

variable "wait_for_cluster" {
  description = "After copying the manifests, wait until the dashboard and the platform (registry, storage, operators, Gateway, certificate) are ready."
  type        = bool
  default     = true
}
