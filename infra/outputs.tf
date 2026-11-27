output "nodes" {
  description = "Node name => IP."
  value       = { for n in local.nodes : n.name => n.ip }
}

output "ssh" {
  description = "SSH commands per node."
  value       = { for n in local.nodes : n.name => "ssh ubuntu@${n.ip}" }
}

output "dashboard_url" {
  description = "Headlamp dashboard (log in with the token from scripts/dashboard-token.sh)."
  value       = "http://${local.master.ip}:${var.dashboard_node_port}"
}

output "kubeconfig" {
  description = "Use the cluster from this machine."
  value       = "export KUBECONFIG=${abspath(path.module)}/kubeconfig"
}

output "dashboard_token_command" {
  description = "Prints the dashboard login token."
  value       = "${abspath(path.module)}/scripts/dashboard-token.sh ${local.master.ip}"
}

output "ca_certificate" {
  description = "CA of the Gateway's HTTPS certificate. Trust it once to avoid browser warnings."
  value       = abspath(local_file.ca_certificate.filename)
}

output "site_url" {
  description = "Applications behind the Gateway (HTTPS; plain HTTP redirects)."
  value       = local.acme_enabled ? "https://${local.acme_domain}/" : "https://${local.master.ip}/"
}

output "registry" {
  description = "Cluster image registry (plain HTTP). Push here, deploy by digest."
  value       = local.registry
}
