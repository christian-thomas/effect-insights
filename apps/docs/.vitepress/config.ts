import { defineConfig } from 'vitepress';

export default defineConfig({
  title: 'Tracewatch',
  description: 'Effect Digital Consent Management Platform — implementation + operator docs',
  cleanUrls: true,
  lastUpdated: true,

  themeConfig: {
    siteTitle: 'Tracewatch',

    nav: [
      { text: 'Guides', link: '/guides/implementing-the-banner' },
      { text: 'Admin app', link: 'https://admin.tracewatch.io', target: '_blank' },
    ],

    sidebar: {
      '/guides/': [
        {
          text: 'Getting started',
          items: [
            { text: 'Implementing the banner', link: '/guides/implementing-the-banner' },
            { text: 'Installing on a website', link: '/guides/installing-on-a-website' },
            { text: 'Installing via Google Tag Manager', link: '/guides/installing-via-gtm' },
            { text: 'Triggering scans', link: '/guides/triggering-scans' },
          ],
        },
        {
          text: 'Configuring',
          items: [
            { text: 'Banner configuration', link: '/guides/banner-configuration' },
            { text: 'Managing domains', link: '/guides/managing-domains' },
            { text: 'Content Security Policy', link: '/guides/csp' },
            { text: 'Cross-subdomain consent', link: '/guides/cross-subdomain-consent' },
            { text: 'Blocking third-party scripts', link: '/guides/blocking-scripts' },
          ],
        },
        {
          text: 'Working with the data',
          items: [
            { text: 'Cookie policy generation', link: '/guides/cookie-policy-generation' },
            { text: 'Accessibility scans', link: '/guides/accessibility-scans' },
            { text: 'Consent records + receipts', link: '/guides/consent-records' },
            { text: 'JavaScript API surface', link: '/guides/javascript-api' },
          ],
        },
        {
          text: 'Operations',
          items: [
            { text: 'Notifications', link: '/guides/notifications' },
            { text: 'Rate limits', link: '/guides/rate-limits' },
            { text: 'Troubleshooting', link: '/guides/troubleshooting' },
          ],
        },
        {
          text: 'Coming soon',
          items: [
            { text: 'Vendor / sub-processor management', link: '/guides/vendors' },
            { text: 'Banner A/B testing', link: '/guides/ab-testing' },
          ],
        },
      ],
    },

    search: {
      provider: 'local',
    },

    socialLinks: [{ icon: 'github', link: 'https://github.com/effect-digital/cmp-project' }],

    footer: {
      message: 'Built and maintained by Effect Digital.',
      copyright: '© Effect Digital',
    },
  },
});
