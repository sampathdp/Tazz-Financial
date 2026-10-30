import { useEffect, useState } from 'react'
import { profileApi } from '../api'

/** Fired after the profile picture is uploaded/removed so every UserAvatar refetches. */
export const PHOTO_EVENT = 'profile-photo-changed'
export const notifyPhotoChanged = (hasPhoto: boolean) => window.dispatchEvent(new CustomEvent(PHOTO_EVENT, { detail: hasPhoto }))

/** The signed-in user's picture (auth-protected, so fetched as a blob) with an initials fallback. */
export function UserAvatar({ name, hasPhoto, size = 34, radius = '50%' }: { name?: string; hasPhoto?: boolean; size?: number; radius?: string | number }) {
  const [has, setHas] = useState(!!hasPhoto)
  const [url, setUrl] = useState('')
  const [bump, setBump] = useState(0)
  useEffect(() => { setHas(!!hasPhoto) }, [hasPhoto])
  useEffect(() => {
    const on = (e: Event) => { setHas(!!(e as CustomEvent).detail); setBump((n) => n + 1) }
    window.addEventListener(PHOTO_EVENT, on)
    return () => window.removeEventListener(PHOTO_EVENT, on)
  }, [])
  useEffect(() => {
    if (!has) { setUrl(''); return }
    let objUrl = ''
    let live = true
    profileApi.photo()
      .then((blob) => { if (!live) return; objUrl = URL.createObjectURL(blob); setUrl(objUrl) })
      .catch(() => live && setUrl(''))
    return () => { live = false; if (objUrl) URL.revokeObjectURL(objUrl) }
  }, [has, bump])

  const initials = (name || 'User').split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase() || 'U'
  return (
    <div style={{ width: size, height: size, borderRadius: radius, overflow: 'hidden', flexShrink: 0, background: 'var(--bg-3)', border: '1px solid var(--line)',
      display: 'grid', placeItems: 'center', fontFamily: 'Saira', fontWeight: 700, fontSize: Math.max(12, Math.round(size * 0.38)), color: 'var(--ac-bright)' }}>
      {url ? <img src={url} alt="" style={{ width: '100%', height: '100%', objectFit: 'cover' }} /> : initials}
    </div>
  )
}
