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

  // Invitation acceptance flow is unauthenticated — the invitee doesn't
  // have a session yet. /invitations/:token previews the invite and
  // their POST to /accept establishes the Sanctum session.
  if (to.path.startsWith('/invitations/')) {
    return;
  }

  if (!auth.isAuthenticated) {
    return navigateTo(`/login?redirect=${encodeURIComponent(to.fullPath)}`);
  }
});
