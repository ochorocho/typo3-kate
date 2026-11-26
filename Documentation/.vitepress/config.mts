import { defineConfig } from 'vitepress'

export default defineConfig({
  title: 'K8s on libvirt',
  description: '3-node Kubernetes cluster on KVM (Odroid H2) and macOS with TYPO3, built with OpenTofu',
  // GitHub Pages serves the site under /<repo>/ (set by .github/workflows/docs.yml)
  base: process.env.DOCS_BASE ?? '/',
  cleanUrls: true,
  lastUpdated: false,

  themeConfig: {
    nav: [
      { text: 'Guide', link: '/guide/getting-started' },
      { text: 'Reference', link: '/reference/variables' },
    ],

    sidebar: [
      {
        text: 'Guide',
        items: [
          { text: 'Getting started', link: '/guide/getting-started' },
          { text: 'macOS host', link: '/guide/macos' },
          { text: 'Odroid H2 host', link: '/guide/odroid' },
          { text: 'Dashboard & kubectl', link: '/guide/dashboard' },
          { text: 'TYPO3 on the cluster', link: '/guide/typo3' },
          { text: 'Day-to-day operations', link: '/guide/operations' },
          { text: 'Troubleshooting', link: '/guide/troubleshooting' },
        ],
      },
      {
        text: 'Reference',
        items: [
          { text: 'Variables', link: '/reference/variables' },
          { text: 'How it works', link: '/reference/architecture' },
          { text: 'TYPO3 architecture', link: '/reference/typo3-architecture' },
          { text: 'Files', link: '/reference/files' },
        ],
      },
    ],

    search: { provider: 'local' },
    outline: { level: [2, 3] },
  },
})
