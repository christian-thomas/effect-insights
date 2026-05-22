export const $api = <T>(
  request: string,
  options: Parameters<typeof $fetch<T>>[1] = {},
): Promise<T> => {
  const config = useRuntimeConfig();
  return $fetch<T>(request, {
    baseURL: config.public.apiBase,
    credentials: 'include',
    ...options,
  });
};
