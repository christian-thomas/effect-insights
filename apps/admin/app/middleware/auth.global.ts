export default defineNuxtRouteMiddleware(async (to) => {
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
