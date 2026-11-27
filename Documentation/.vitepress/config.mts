import { defineConfig } from 'vitepress'
import { withMermaid } from 'vitepress-plugin-mermaid'

// GitHub Pages serves the site under /<repo>/ (set by .github/workflows/docs.yml)
const base = process.env.DOCS_BASE ?? '/'

export default withMermaid(defineConfig({
  title: 'K8s on libvirt',
  description: '3-node Kubernetes cluster on KVM (Odroid H2) and macOS with TYPO3, built with OpenTofu',
  base,
  // head entries don't get the base path by themselves
  head: [['link', { rel: 'icon', type: 'image/svg+xml', href: `${base}favicon.svg` }]],
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
          { text: 'Raspberry Pi 5 host', link: '/guide/raspberry-pi5' },
          { text: 'Dashboard & kubectl', link: '/guide/dashboard' },
          { text: 'TYPO3 on the cluster', link: '/guide/typo3' },
          { text: "Public domain & Let's Encrypt", link: '/guide/public-access' },
          { text: 'Day-to-day operations', link: '/guide/operations' },
          { text: 'Troubleshooting', link: '/guide/troubleshooting' },
        ],
      },
      {
        text: 'Reference',
        items: [
          { text: 'Settings (kate.yaml)', link: '/reference/settings' },
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

  // Mermaid diagrams (```mermaid blocks), drawn in the browser, in TYPO3's
  // colours: light orange boxes with dark text read on both themes. The
  // system font, because Mermaid sizes the boxes before a web font has
  // loaded, which cut off their text.
  mermaid: {
    theme: 'base',
    themeVariables: {
      fontFamily: 'ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
      fontSize: '15px',
      primaryColor: '#ffe7cc',
      primaryTextColor: '#1a1a1a',
      primaryBorderColor: '#ff8700',
      secondaryColor: '#f4f4f4',
      tertiaryColor: '#fff6ec',
      lineColor: '#ff8700',
      clusterBkg: '#fff6ec',
      clusterBorder: '#ffb366',
      edgeLabelBackground: '#ffffff',
      titleColor: '#1a1a1a',
    },
  },
}))
