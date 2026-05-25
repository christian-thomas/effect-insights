import { defineConfig } from 'vitepress';

export default defineConfig({
  title: 'Effect Insight',
  description:
    'Effect Digital website audit platform — consent management + accessibility auditing, operator + implementation docs',
  cleanUrls: true,
  lastUpdated: true,

  themeConfig: {
    siteTitle: 'Effect Insight',

    nav: [
      { text: 'Consent', link: '/guides/implementing-the-banner' },
      { text: 'Accessibility', link: '/guides/accessibility-scans' },
      { text: 'Admin app', link: 'https://admin.effectinsight.com', target: '_blank' },
    ],

    sidebar: {
      '/guides/': [
        {
          text: 'Platform',
          items: [
            { text: 'Managing domains', link: '/guides/managing-domains' },
            { text: 'Organisations (multi-tenant)', link: '/guides/organisations' },
            { text: 'Authentication (passkeys + password)', link: '/guides/auth' },
            { text: 'User management (invitations)', link: '/guides/user-management' },
            { text: 'Personal account settings', link: '/guides/personal-account-settings' },
          ],
        },
        {
          text: 'Consent management',
          items: [
            { text: 'Implementing the banner', link: '/guides/implementing-the-banner' },
            { text: 'Installing on a website', link: '/guides/installing-on-a-website' },
            { text: 'Installing via Google Tag Manager', link: '/guides/installing-via-gtm' },
            { text: 'Banner configuration', link: '/guides/banner-configuration' },
            { text: 'Triggering cookie scans', link: '/guides/triggering-scans' },
            { text: 'Cookie policy generation', link: '/guides/cookie-policy-generation' },
            { text: 'Cookie database management', link: '/guides/cookie-management' },
            { text: 'Consent records + receipts', link: '/guides/consent-records' },
            { text: 'Blocking third-party scripts', link: '/guides/blocking-scripts' },
            { text: 'Cross-subdomain consent', link: '/guides/cross-subdomain-consent' },
            { text: 'Content Security Policy', link: '/guides/csp' },
            { text: 'JavaScript API surface', link: '/guides/javascript-api' },
          ],
        },
        {
          text: 'Accessibility scanning',
          items: [{ text: 'Accessibility scans', link: '/guides/accessibility-scans' }],
        },
        {
          text: 'Operations',
          items: [
            { text: 'Notifications', link: '/guides/notifications' },
            { text: 'Rate limits', link: '/guides/rate-limits' },
            {
              text: 'Troubleshooting',
              collapsed: true,
              items: [
                { text: 'Consent management', link: '/guides/troubleshooting/consent' },
                { text: 'Accessibility', link: '/guides/troubleshooting/accessibility' },
                { text: 'Platform', link: '/guides/troubleshooting/platform' },
              ],
            },
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
