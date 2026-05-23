const UNSAFE_METHODS = new Set(['POST', 'PUT', 'PATCH', 'DELETE']);

const readXsrfCookie = (): string | null => {
  if (import.meta.server) return null;
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
  if (!match || !match[1]) return null;
  return decodeURIComponent(match[1]);
};

const ensureCsrfCookie = async (baseURL: string): Promise<void> => {
  if (import.meta.server) return;
  if (readXsrfCookie()) return;
  await $fetch('/sanctum/csrf-cookie', { baseURL, credentials: 'include' });
};

export const $api = async <T>(
  request: string,
  options: Parameters<typeof $fetch<T>>[1] = {},
): Promise<T> => {
  const config = useRuntimeConfig();
  const baseURL = config.public.apiBase;
  const method = (options.method ?? 'GET').toString().toUpperCase();

  if (UNSAFE_METHODS.has(method)) {
    await ensureCsrfCookie(baseURL);
  }

  const xsrf = readXsrfCookie();
  const headers = {
    Accept: 'application/json',
    ...(xsrf ? { 'X-XSRF-TOKEN': xsrf } : {}),
    ...options.headers,
  };

  return $fetch<T>(request, {
    baseURL,
    credentials: 'include',
    ...options,
    headers,
  });
};
