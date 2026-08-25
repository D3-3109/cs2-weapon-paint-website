/**
 * Mirrors every remote image referenced by frontend/public/data/*_en.json
 * into frontend/public/img/<category>/<name>.webp and rewrites the data
 * files in place: `image` -> local path, original kept in `image_remote`.
 *
 * Sources today: raw.githubusercontent.com (Nereziel) and
 * community.akamai.steamstatic.com (Steam CDN). Any http(s) URL found in an
 * `image` field is mirrored, so future hosts are covered automatically.
 *
 * Skin filenames follow the upstream convention `<weapon_name>-<paint>.webp`
 * so components can compute local paths deterministically (see
 * frontend/src/lib/mirroredSkin.js).
 *
 * Usage:
 *   node scripts/mirror-images.mjs              # download missing + rewrite JSONs
 *   node scripts/mirror-images.mjs --check      # verify coverage only, no writes
 *   node scripts/mirror-images.mjs --force      # redownload everything
 *   node scripts/mirror-images.mjs --limit=20   # small-batch test run
 */
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import fse from 'fs-extra'
import sharp from 'sharp'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const root = path.resolve(__dirname, '..')
const dataDir = path.join(root, 'frontend', 'public', 'data')
const imgRoot = path.join(root, 'frontend', 'public', 'img')

const CATEGORIES = [
  { dir: 'skins', file: 'skins_en.json' },
  { dir: 'gloves', file: 'gloves_en.json' },
  { dir: 'stickers', file: 'stickers_en.json' },
  { dir: 'keychains', file: 'keychains_en.json' },
  { dir: 'music-kits', file: 'music_kits_en.json' },
  { dir: 'music', file: 'music_en.json' },
  { dir: 'agents', file: 'agents_en.json' },
  { dir: 'collectibles', file: 'collectibles_en.json' },
]

const args = process.argv.slice(2)
const checkOnly = args.includes('--check')
const force = args.includes('--force')
const limitArg = args.find((a) => a.startsWith('--limit='))
const limit = limitArg ? Number(limitArg.split('=')[1]) : 0
const concurrency =
  Number((args.find((a) => a.startsWith('--concurrency=')) || '').split('=')[1]) || 12

function log(msg) {
  console.log(`[img-mirror] ${msg}`)
}

function slugify(text) {
  return (
    String(text)
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '') || 'item'
  )
}

/** Deterministic local webp path for a data entry. */
function entryLocalPath(category, entry) {
  let base = null
  if (category === 'skins') {
    if (entry.weapon_name && entry.paint != null) base = `${entry.weapon_name}-${entry.paint}`
  } else if (category === 'gloves') {
    if (entry.weapon_defindex != null && entry.paint != null) {
      base = `${entry.weapon_defindex}-${entry.paint}`
    }
  } else if (entry.id != null && entry.id !== '') {
    base = String(entry.id)
  }
  if (!base) base = slugify(entry.paint_name || entry.agent_name || entry.name || '')
  return `/img/${category}/${base}.webp`
}

// Keys derived from unique ids (weapon+paint, numeric id) are stable and must
// NOT get collision suffixes; slug-derived ones may collide legitimately.
function entryKeyIsStable(category, entry) {
  if (category === 'skins') return Boolean(entry.weapon_name && entry.paint != null)
  if (category === 'gloves') return entry.weapon_defindex != null && entry.paint != null
  return entry.id != null && entry.id !== ''
}

async function fetchOne(url) {
  const delays = [1000, 3000, 9000]
  for (let attempt = 0; ; attempt += 1) {
    try {
      const ctrl = new AbortController()
      const timer = setTimeout(() => ctrl.abort(), 25000)
      const res = await fetch(url, {
        signal: ctrl.signal,
        headers: { 'User-Agent': 'Mozilla/5.0 (compatible; wp-image-mirror/1.0)' },
      })
      clearTimeout(timer)
      if (res.status === 404) {
        const err = new Error('HTTP 404')
        err.code = 'UPSTREAM_MISSING'
        throw err
      }
      if (!res.ok) throw new Error(`HTTP ${res.status}`)
      const buf = Buffer.from(await res.arrayBuffer())
      if (buf.length < 100) throw new Error(`too small (${buf.length}B)`)
      return buf
    } catch (err) {
      if (attempt >= delays.length) throw err
      await new Promise((r) => setTimeout(r, delays[attempt]))
    }
  }
}

/** In-flight download dedup: identical URL fetched once even across categories. */
const inflight = new Map()
function downloadOnce(url) {
  if (inflight.has(url)) return inflight.get(url)
  const p = fetchOne(url).finally(() => inflight.delete(url))
  inflight.set(url, p)
  return p
}
function collectTasks() {
  const tasks = []
  let seenUrls = new Set()
  let uniqueUrls = 0



  for (const cat of CATEGORIES) {
    const filePath = path.join(dataDir, cat.file)
    if (!fs.existsSync(filePath)) continue
    const entries = JSON.parse(fs.readFileSync(filePath, 'utf8'))
    const used = new Set()

    entries.forEach((entry, idx) => {
      const img = typeof entry.image === 'string' ? entry.image : ''
      const remoteFallback =
        typeof entry.image_remote === 'string' && /^https?:\/\//i.test(entry.image_remote)
          ? entry.image_remote
          : ''

      // Self-heal: entry already points at a local path but the file is gone
      // (deleted by hand, interrupted cleanup, ...) -> re-fetch from image_remote.
      if (img.startsWith('/img/') && remoteFallback) {
        const abs = path.join(imgRoot, img.replace('/img/', ''))
        if (!fs.existsSync(abs)) {
          tasks.push({
            category: cat.dir,
            file: cat.file,
            entry,
            idx,
            remote: remoteFallback,
            local: img,
            restoring: true,
          })
        }
        return
      }

      if (!/^https?:\/\//i.test(img)) return // intentionally empty
      const remote = img
      if (!seenUrls.has(remote)) seenUrls.add(remote)
      let local = entryLocalPath(cat.dir, entry)
      // Slug-derived keys can collide legitimately -> numeric suffix.
      if (!entryKeyIsStable(cat.dir, entry)) {
        const stem = local.replace(/\.webp$/, '')
        let n = 2
        while (used.has(local)) local = `${stem}-${n++}.webp`
      } else if (used.has(local)) {
        log(`UWAGA: duplicate key in ${cat.file} #${idx}: ${local}`)
      }
      used.add(local)
      tasks.push({ category: cat.dir, file: cat.file, entry, idx, remote, local })
    })
  }
  uniqueUrls = seenUrls.size
  log(`扫描到 ${tasks.length} 个图片引用(${uniqueUrls} 个唯一 URL)`)
  return tasks
}

/**
 * Secondary source: when the primary upstream 404s (Nereziel repo lacks some
 * newer collectibles/music kits), fall back to ByMykel/CSGO-API matched by
 * entry id (same numbering) or normalized name. ByMykel images point at
 * Steam CDN directly, so they work wherever the primary source does.
 */
const FALLBACK_ENDPOINTS = {
  skins: 'skins.json',
  gloves: 'gloves.json',
  stickers: 'stickers.json',
  'music-kits': 'music_kits.json',
  music: 'music_kits.json',
  agents: 'agents.json',
  collectibles: 'collectibles.json',
}
const BEMYKEL_BASE = 'https://raw.githubusercontent.com/ByMykel/CSGO-API/main/public/api/en/'
const fallbackMaps = new Map() // endpoint -> Promise<Map<key, url>>

function normName(s) {
  return String(s)
    .toLowerCase()
    .replace(/^(music kit|sticker|charm|collectible|patch|pin)\s*\|\s*/i, '')
    .replace(/[^a-z0-9]+/g, '')
}

async function lookupFallback(task) {
  const endpoint = FALLBACK_ENDPOINTS[task.category]
  if (!endpoint) return null
  let mapP = fallbackMaps.get(endpoint)
  if (!mapP) {
    mapP = (async () => {
      const res = await fetch(BEMYKEL_BASE + endpoint)
      if (!res.ok) throw new Error(`fallback API HTTP ${res.status}`)
      const arr = await res.json()
      const map = new Map()
      for (const it of arr) {
        const url = typeof it.image === 'string' && /^https?:\/\//i.test(it.image) ? it.image : null
        if (!url) continue
        if (it.id != null) map.set('id:' + String(it.id).replace(/^.*?-/, ''), url)
        if (it.def_index != null) map.set('id:' + String(it.def_index), url)
        if (it.name) map.set('name:' + normName(it.name), url)
      }
      return map
    })().catch((err) => {
      fallbackMaps.delete(endpoint)
      throw err
    })
    fallbackMaps.set(endpoint, mapP)
  }
  const map = await mapP
  const e = task.entry
  return (
    (e.id != null && map.get('id:' + String(e.id))) ||
    (e.name ? map.get('name:' + normName(e.name)) : null) ||
    (e.paint_name ? map.get('name:' + normName(e.paint_name)) : null) ||
    null
  )
}

async function mirrorAndRewrite(task) {
  const { entry, remote, local } = task
  const absOut = path.join(imgRoot, local.replace('/img/', ''))
  const exists = fs.existsSync(absOut) && fs.statSync(absOut).size > 0

  if (checkOnly) {
    // Entries still pointing at a remote URL were intentionally not mirrored
    // (e.g. upstream 404) - skip them instead of reporting missing files.
    if (/^https?:\/\//i.test(entry.image)) return 'remote-kept'
    const abs = path.join(imgRoot, entry.image.replace('/img/', ''))
    const ok = fs.existsSync(abs) && fs.statSync(abs).size > 0
    return ok ? 'ok' : 'missing'
  }

  if (!exists || force) {
    let buf
    let sourceUrl = remote
    try {
      buf = await downloadOnce(remote)
    } catch (err) {
      if (err.code !== 'UPSTREAM_MISSING') throw err
      const fallbackUrl = await lookupFallback(task)
      if (!fallbackUrl) throw err
      buf = await downloadOnce(fallbackUrl)
      sourceUrl = fallbackUrl
      task.fellBack = true
    }
    const webp = await sharp(buf).webp({ quality: 82, alphaQuality: 90 }).toBuffer()
    await fse.ensureDir(path.dirname(absOut))
    await fs.promises.writeFile(absOut, webp)
    if (task.fellBack && entry.image_remote !== sourceUrl) {
      entry.image_remote = sourceUrl // provenance: URL this file actually came from
      task.dirty = true // restore-flow tasks (image already local) need this persisted
    }
  }

  // Mark rewrite on this task; applied to disk after workers finish.
  if (entry.image !== local) {
    entry.image_remote = entry.image_remote ?? remote
    entry.image = local
    task.dirty = true
  } else {
    task.dirty = false
  }
  return exists && !force ? 'ok-exists' : 'downloaded'
}

async function main() {
  const t0 = Date.now()
  const tasks = collectTasks()
  const scoped = limit > 0 ? tasks.slice(0, limit) : tasks
  log(
    `任务 ${scoped.length}${checkOnly ? ' [CHECK]' : ''}${force ? ' [FORCE]' : ''} 并发=${concurrency}`,
  )

  const counts = { downloaded: 0, 'ok-exists': 0, ok: 0, 'remote-kept': 0, missing: 0, failed: 0 }
  const failures = []
  const touchedFiles = new Set()

  if (checkOnly) {
    for (const t of scoped) {
      const r = await mirrorAndRewrite(t)
      counts[r] = (counts[r] || 0) + 1
      if (r === 'missing') failures.push(`${t.file}#${t.idx} ${t.local}`)
    }
  } else {
    let done = 0
    const queue = [...scoped]
    async function worker() {
      for (;;) {
        const t = queue.shift()
        if (!t) return
        try {
          const r = await mirrorAndRewrite(t)
          counts[r] = (counts[r] || 0) + 1
          if (t.dirty) touchedFiles.add(t.file)
        } catch (err) {
          if (err.code === 'UPSTREAM_MISSING') {
            // Upstream never had this file - keep remote URL, warn only.
            counts['upstream-missing'] = (counts['upstream-missing'] || 0) + 1
            failures.push(`${t.file}#${t.idx} upstream 404: ${t.remote.slice(-46)}`)
          } else {
            counts.failed += 1
            failures.push(`${t.file}#${t.idx} ${t.remote} -> ${err.message}`)
          }
        }
        done += 1
        if (done % 250 === 0 || done === scoped.length) {
          log(
            `进度 ${done}/${scoped.length} (下载 ${counts.downloaded}, 已有 ${counts['ok-exists']}, 失败 ${counts.failed})`,
          )
        }
      }
    }
    await Promise.all(Array.from({ length: Math.min(concurrency, scoped.length || 1) }, worker))

    // Persist rewritten JSONs against fresh parses keyed by array index.
    for (const cat of CATEGORIES) {
      if (!touchedFiles.has(cat.file)) continue
      const filePath = path.join(dataDir, cat.file)
      const entries = JSON.parse(fs.readFileSync(filePath, 'utf8'))
      const byIdx = new Map(
        scoped.filter((t) => t.file === cat.file && t.dirty).map((t) => [t.idx, t]),
      )
      let changed = 0
      for (const [idx, t] of byIdx) {
        const entry = entries[idx]
        if (!entry) continue
        // task.entry holds the authoritative post-mirror values (local path +
        // actual source URL incl. ByMykel fallback). Copy, don't recompute.
        if (entry.image !== t.entry.image) entry.image = t.entry.image
        if (t.entry.image_remote != null && entry.image_remote !== t.entry.image_remote) {
          entry.image_remote = t.entry.image_remote
        }
        changed += 1
      }
      if (changed > 0) {
        await fs.promises.writeFile(filePath, `${JSON.stringify(entries, null, 4)}\n`, 'utf8')
        log(`改写 ${cat.file} (${changed} 条)`)
      }
    }
  }

  const secs = ((Date.now() - t0) / 1000).toFixed(1)
  log(`完成于 ${secs}s -> ${JSON.stringify(counts)}`)
  const hardFailures = failures.filter((f) => !f.includes('upstream 404'))
  const upstreamMissing = counts['upstream-missing'] || 0
  if (upstreamMissing > 0) {
    log(`上游缺失 ${upstreamMissing} 条(保持远程URL,不影响构建):`)
    for (const f of failures.filter((f) => f.includes('upstream 404')).slice(0, 20)) {
      log(`  - ${f}`)
    }
  }
  if (hardFailures.length > 0 || counts.missing > 0) {
    log(`失败 ${hardFailures.length} 条:`)
    for (const f of hardFailures.slice(0, 25)) log(`  - ${f}`)
    if (hardFailures.length > 25) log(`  ... 以及另外 ${hardFailures.length - 25} 条`)
    process.exitCode = 1
  }
}

main().catch((err) => {
  console.error(err)
  process.exit(1)
})
