locals {
  # ../kate.yaml (git-ignored, see kate.example.yaml): cluster-wide settings,
  # e.g. the ACME contact and token. They win over the tfvars and TF_VAR_*
  # variables. A missing file is fine, a broken one fails the run. Domains
  # are per project: projects/<name>/kate.yaml, used by k8s/deploy.sh.
  kate_file = "${path.module}/../kate.yaml"
  kate      = fileexists(local.kate_file) ? yamldecode(file(local.kate_file)) : null

  # An empty value in kate.yaml counts as unset.
  acme_configured = try(local.kate.acme, null) != null
  acme_email      = try(coalesce(try(local.kate.acme.email, null), var.acme_email), null)
  # Extra public keys for the ubuntu user on every node (scripts/authorized-keys.sh).
  ssh_authorized_keys = [for k in try(tolist(local.kate.ssh.authorizedKeys), []) : trimspace(k) if trimspace(k) != ""]
  hetzner_dns_token = sensitive(try(coalesce(
    try(local.kate.acme.hetznerDnsToken, null),
    nonsensitive(var.hetzner_dns_token),
  ), null))
}

# Settings that moved to projects/<name>/kate.yaml: a warning, not an error.
check "kate_yaml_per_project_settings" {
  assert {
    condition     = try(local.kate.domain, null) == null && try(local.kate.acme.environment, null) == null
    error_message = "domain and acme.environment in kate.yaml are ignored now: each site sets them in projects/<name>/kate.yaml (k8s/deploy.sh). Remove them from kate.yaml."
  }
}
