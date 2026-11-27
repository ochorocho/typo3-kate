locals {
  # ../kate.yaml (git-ignored, see kate.example.yaml): cluster-wide settings,
  # e.g. the ACME contact and the DNS providers (dns.tf). They win over the
  # tfvars and TF_VAR_* variables. A missing file is fine, a broken one fails the run. Domains
  # are per project: projects/<name>/kate.yaml, used by ./kate deploy.
  kate_file = "${path.module}/../kate.yaml"
  kate      = fileexists(local.kate_file) ? yamldecode(file(local.kate_file)) : null

  # An empty value in kate.yaml counts as unset.
  acme_configured = try(local.kate.acme, null) != null
  acme_email      = try(coalesce(try(local.kate.acme.email, null), var.acme_email), null)
  # Extra public keys for the ubuntu user on every node (scripts/authorized-keys.sh).
  ssh_authorized_keys = [for k in try(tolist(local.kate.ssh.authorizedKeys), []) : trimspace(k) if trimspace(k) != ""]
}
