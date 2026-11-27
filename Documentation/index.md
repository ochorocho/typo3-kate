---
layout: home

hero:
  name: K8s on libvirt
  image:
    src: /logo.svg
    alt: Three connected cluster nodes, each holding a site
  text: A 3-node Kubernetes cluster in one command
  tagline: OpenTofu builds Ubuntu VMs on KVM (Odroid H2) or macOS, installs k3s and a web dashboard.
  actions:
    - theme: brand
      text: Get started
      link: /guide/getting-started
    - theme: alt
      text: How it works
      link: /reference/architecture

features:
  - title: Ready to use
    details: One master and two workers with static IPs. k3s ships with Traefik ingress, CoreDNS, metrics-server and local-path storage.
  - title: Dashboard included
    details: Headlamp runs on port 30080 with an admin login token. tofu apply waits until it is up and writes a kubeconfig for you.
  - title: TYPO3 included
    details: The production image and Helm chart from Benni Mack's typo3-k8s-starter, with MariaDB, Valkey and shared NFS storage in the cluster. One script deploys it.
    link: /guide/typo3
  - title: Three hosts, one config
    details: KVM on the Odroid H2 (x86_64), QEMU with Apple's Hypervisor.framework on Apple Silicon (arm64), and a prepared Raspberry Pi 5 setup (arm64, KVM). One settings file per host.
---
