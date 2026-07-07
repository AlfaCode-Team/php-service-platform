

export type PageLoader<T> = () => Promise<T> | T;
export type PagesMap<T> = Record<string, PageLoader<T> | T>;

/**
 * Given a path (or list of candidate paths) and a map of pages,
 * returns the first matching page component (resolving if it's a loader).
 *
 * @param path - single key or array of keys to try in order
 * @param pages - record of page components or async loaders
 * @throws if none of the provided path(s) exist in the pages map
 */
export async function resolvePageComponent<T>(
  path: string | string[],
  pages: PagesMap<T>
): Promise<T> {
  const candidates = Array.isArray(path) ? path : [path];

  for (const key of candidates) {
    const entry = pages[key];

    if (entry === undefined) {
      continue;
    }

    if (typeof entry === 'function') {
      // It's a loader; call and await if necessary
      const result = (entry as PageLoader<T>)();
      return result instanceof Promise ? await result : result;
    }

    // Direct value
    return entry as T;
  }

  throw new Error(`Page not found: ${Array.isArray(path) ? path.join(', ') : path}`);
}
