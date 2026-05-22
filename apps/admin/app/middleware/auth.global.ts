export default defineNuxtRouteMiddleware(async (to) => {
  // Auth state lives in browser cookies + Pinia — defer the redirect logic to
  // client hydration so SSR doesn't need to proxy session cookies upstream.
  if (import.meta.server) return;

  const auth = useAuthStore();

  if (!auth.user) {
    await auth.fetchSession();
  }

  if (to.path === '/login') {
    if (auth.isAuthenticated) {
      return navigateTo('/');
    }
    return;
  }

  if (!auth.isAuthenticated) {
    return navigateTo(`/login?redirect=${encodeURIComponent(to.fullPath)}`);
  }
});
