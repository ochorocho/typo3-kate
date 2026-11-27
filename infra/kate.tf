locals {
  # ../kate.yaml (git-ignored, see kate.example.yaml): your domain, ACME
  # settings and token. They win over the tfvars and TF_VAR_* variables.
  # A missing file is fine, a broken one fails the run.
  kate_file = "${path.module}/../kate.yaml"
  kate      = fileexists(local.kate_file) ? yamldecode(file(local.kate_file)) : null

  # An empty value in kate.yaml counts as unset.
  acme_domain      = try(coalesce(try(local.kate.domain, null), var.acme_domain), null)
  acme_environment = try(coalesce(try(local.kate.acme.environment, null), var.acme_environment), var.acme_environment)
  acme_email       = try(coalesce(try(local.kate.acme.email, null), var.acme_email), null)
  # Extra public keys for the ubuntu user on every node (scripts/authorized-keys.sh).
  ssh_authorized_keys = [for k in try(tolist(local.kate.ssh.authorizedKeys), []) : trimspace(k) if trimspace(k) != ""]
  hetzner_dns_token = sensitive(try(coalesce(
    try(local.kate.acme.hetznerDnsToken, null),
    nonsensitive(var.hetzner_dns_token),
  ), null))
}
