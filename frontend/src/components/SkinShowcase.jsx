import { useEffect, useState } from 'react'
import { useI18n } from '../i18n/I18nProvider'
import { mirroredSkinPath } from '../lib/mirroredSkin'
import '../styles/SkinShowcase.css'

/** Curated iconic skins - weapon/paint matched to public/data/skins_en.json */
const FEATURED = [
  {
    paint_name: 'AK-47 | Inheritance',
    weapon: 'weapon_ak47',
    paint: '1171',
    remote:
      'https://raw.githubusercontent.com/Nereziel/cs2-WeaponPaints/main/website/img/skins/weapon_ak47-1171.png',
  },
  {
    paint_name: 'AWP | Asiimov',
    weapon: 'weapon_awp',
    paint: '279',
    remote:
      'https://raw.githubusercontent.com/Nereziel/cs2-WeaponPaints/main/website/img/skins/weapon_awp-279.png',
  },
  {
    paint_name: 'M4A1-S | Printstream',
    weapon: 'weapon_m4a1_silencer',
    paint: '984',
    remote:
      'https://raw.githubusercontent.com/Nereziel/cs2-WeaponPaints/main/website/img/skins/weapon_m4a1_silencer-984.png',
  },
  {
    paint_name: 'Karambit | Doppler',
    weapon: 'weapon_knife_karambit',
    paint: '417',
    remote:
      'https://raw.githubusercontent.com/Nereziel/cs2-WeaponPaints/main/website/img/skins/weapon_knife_karambit-417.png',
  },
  {
    paint_name: 'Desert Eagle | Printstream',
    weapon: 'weapon_deagle',
    paint: '962',
    remote:
      'https://raw.githubusercontent.com/Nereziel/cs2-WeaponPaints/main/website/img/skins/weapon_deagle-962.png',
  },
  {
    paint_name: 'AK-47 | Asiimov',
    weapon: 'weapon_ak47',
    paint: '801',
    remote:
      'https://raw.githubusercontent.com/Nereziel/cs2-WeaponPaints/main/website/img/skins/weapon_ak47-801.png',
  },
  {
    paint_name: 'USP-S | Kill Confirmed',
    weapon: 'weapon_usp_silencer',
    paint: '504',
    remote:
      'https://raw.githubusercontent.com/Nereziel/cs2-WeaponPaints/main/website/img/skins/weapon_usp_silencer-504.png',
  },
  {
    paint_name: 'Glock-18 | Fade',
    weapon: 'weapon_glock',
    paint: '38',
    remote:
      'https://raw.githubusercontent.com/Nereziel/cs2-WeaponPaints/main/website/img/skins/weapon_glock-38.png',
  },
].map((skin) => ({ ...skin, local: mirroredSkinPath(skin.weapon, skin.paint) ?? skin.remote }))

const INTERVAL_MS = 3200

export default function SkinShowcase() {
  const { t } = useI18n()
  const [index, setIndex] = useState(0)
  const [ready, setReady] = useState(() => new Set([0]))

  useEffect(() => {
    FEATURED.forEach((skin, i) => {
      const img = new Image()
      const markReady = () => {
        setReady((prev) => {
          if (prev.has(i)) return prev
          const next = new Set(prev)
          next.add(i)
          return next
        })
      }
      // Local mirror first; on failure try the original remote once.
      img.onload = markReady
      img.onerror = () => {
        if (img.src !== skin.remote) {
          img.src = skin.remote
        } else {
          markReady()
        }
      }
      img.src = skin.local
    })
  }, [])

  useEffect(() => {
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches
    if (reduced) return undefined
    const id = setInterval(() => {
      setIndex((i) => {
        for (let step = 1; step <= FEATURED.length; step += 1) {
          const next = (i + step) % FEATURED.length
          if (ready.has(next)) return next
        }
        return i
      })
    }, INTERVAL_MS)
    return () => clearInterval(id)
  }, [ready])

  return (
    <div className="skin-showcase" aria-label={t.skinShowcaseLabel}>
      <div className="skin-showcase__stage">
        <span className="skin-showcase__glow" aria-hidden="true" />
        {FEATURED.map((skin, i) => {
          const active = i === index && ready.has(i)
          return (
            <div
              key={skin.local}
              className={active ? 'skin-showcase__slide is-active' : 'skin-showcase__slide'}
              aria-hidden={!active}
            >
              <img
                className="skin-showcase__img"
                src={skin.local}
                alt=""
                draggable={false}
                loading={i === 0 ? 'eager' : 'lazy'}
              />
            </div>
          )
        })}
        <div className="skin-showcase__floor" aria-hidden="true" />
      </div>

      <div className="skin-showcase__meta">
        <span className="skin-showcase__eyebrow">{t.skinShowcaseLabel}</span>
        <div className="skin-showcase__caption">
          {FEATURED.map((skin, i) => (
            <strong
              key={skin.paint_name}
              className={
                i === index ? 'skin-showcase__name is-active' : 'skin-showcase__name'
              }
            >
              {skin.paint_name}
            </strong>
          ))}
        </div>
        <div className="skin-showcase__dots" aria-hidden="true">
          {FEATURED.map((_, i) => (
            <span
              key={i}
              className={i === index ? 'skin-showcase__dot is-active' : 'skin-showcase__dot'}
            />
          ))}
        </div>
      </div>
    </div>
  )
}
