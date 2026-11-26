terraform {
  required_version = ">= 1.8.0"

  required_providers {
    # 0.8.x is the classic schema (libvirt_domain/libvirt_volume/libvirt_cloudinit_disk).
    # 0.9+ is a full rewrite with a different schema, so stay on 0.8.
    libvirt = {
      source  = "dmacvicar/libvirt"
      version = "~> 0.8.3"
    }
    random = {
      source  = "hashicorp/random"
      version = "~> 3.6"
    }
  }
}

provider "libvirt" {
  uri = local.libvirt_uri
}
