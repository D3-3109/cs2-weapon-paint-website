/**
 * Local paths for skin images mirrored by scripts/mirror-images.mjs
 * (`frontend/public/img/skins/<weapon_name>-<paint>.webp`).
 * Callers keep the original remote URL as onError fallback so the UI still
 * works before the mirror has been populated (fresh dev checkout).
 */

export function mirroredSkinPath(weaponName, paint) {
  if (!weaponName || paint == null || paint === '') return null
  return `/img/skins/${weaponName}-${paint}.webp`
}

/** Swaps an <img> to `fallback` once if the local path failed to load. */
export function withRemoteFallback(fallback) {
  return (event) => {
    const img = event.currentTarget
    if (!img.dataset.wpFallback && fallback && !img.src.endsWith(fallback)) {
      img.dataset.wpFallback = '1'
      img.src = fallback
    }
  }
}
