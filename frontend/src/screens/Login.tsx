import { useEffect, useState, type FormEvent } from 'react'
import { Icon } from '../components/Icon'
import { Btn } from '../components/ui'
import { auth, company, type AuthUser } from '../api'

const FEATURES: { icon: string; label: string }[] = [
  { icon: 'truck', label: 'Track stock across every store in real time' },
  { icon: 'chart', label: 'Reports and KPIs built for daily decisions' },
  { icon: 'shield', label: 'Role-based access keeps your data secure' },
]

export default function Login({ onAuth }: { onAuth: (user: AuthUser) => void }) {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [showPw, setShowPw] = useState(false)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState<string | null>(null)
  const [hasLogo, setHasLogo] = useState(false)
  const [brand, setBrand] = useState({ name: 'Distributor Management System', tagline: 'Distribution & Supply Chain Management' })

  useEffect(() => {
    company.branding().then((b) => setBrand({ name: b.name, tagline: b.tagline })).catch(() => {})
  }, [])

  async function submit(e: FormEvent) {
    e.preventDefault()
    setBusy(true); setErr(null)
    try {
      const { token, user } = await auth.login(email.trim(), password)
      auth.setToken(token)
      localStorage.setItem('mms-route', 'dash')
      onAuth(user)
    } catch (ex: any) {
      const msg = ex?.response?.data?.message
        || ex?.response?.data?.errors?.email?.[0]
        || 'Login failed. Check credentials and try again.'
      setErr(msg)
    } finally {
      setBusy(false)
    }
  }

  const input: React.CSSProperties = {
    background: 'var(--bg-2)', border: '1px solid var(--line)', borderRadius: 'var(--r-m)',
    padding: '12px 14px 12px 40px', color: 'var(--tx-0)', fontSize: 14, fontFamily: 'IBM Plex Sans',
    width: '100%', outline: 'none', transition: 'border-color .15s var(--ease), box-shadow .15s var(--ease)',
  }

  return (
    <div className="login-bg" style={{
      minHeight: '100vh', position: 'relative', display: 'grid', placeItems: 'center',
      padding: 24, overflow: 'hidden', background: 'var(--bg-0)',
    }}>
      <style>{`
        .login-input:focus{ border-color: var(--ac-line) !important; box-shadow: 0 0 0 3px var(--ac-dim); }
        .login-card{ grid-template-columns: 1.05fr 1fr; }
        @media (max-width: 760px){ .login-card{ grid-template-columns: 1fr !important; } .login-aside{ display:none !important; } }
        .login-bg::before{
          content: ''; position: absolute; inset: -15%; pointer-events: none; z-index: 0;
          background:
            radial-gradient(640px 640px at 10% 12%, var(--ac-dim), transparent 60%),
            radial-gradient(600px 600px at 92% 88%, oklch(0.62 0.19 256 / 0.16), transparent 55%),
            radial-gradient(460px 460px at 88% 10%, oklch(0.66 0.15 158 / 0.12), transparent 55%);
          filter: blur(6px);
        }
        .login-bg::after{
          content: ''; position: absolute; inset: 0; pointer-events: none; z-index: 0; opacity: .5;
          background-image: radial-gradient(var(--line) 1px, transparent 1px);
          background-size: 26px 26px;
          -webkit-mask-image: radial-gradient(ellipse 60% 55% at 50% 42%, black 25%, transparent 72%);
          mask-image: radial-gradient(ellipse 60% 55% at 50% 42%, black 25%, transparent 72%);
        }
      `}</style>

      <div className="login-card fade-in" style={{
        position: 'relative', zIndex: 1,
        width: '100%', maxWidth: 880, display: 'grid',
        background: 'var(--bg-1)', border: '1px solid var(--line)', borderRadius: 'var(--r-xl)',
        overflow: 'hidden', boxShadow: '0 24px 70px -20px oklch(0 0 0 / 0.35), var(--sh-3)',
      }}>
        <div className="login-aside" style={{
          position: 'relative', padding: 40, display: 'flex', flexDirection: 'column',
          justifyContent: 'space-between', color: '#fff', overflow: 'hidden',
          background: 'linear-gradient(155deg, var(--ac) 0%, var(--ac-bright) 45%, oklch(0.3 0.05 256) 100%)',
        }}>
          <div style={{
            position: 'absolute', inset: 0, opacity: 0.55,
            background: 'radial-gradient(460px 460px at 100% 0%, oklch(1 0 0 / 0.18), transparent 60%), radial-gradient(380px 380px at 0% 100%, oklch(0 0 0 / 0.28), transparent 60%)',
          }} />
          <div style={{ position: 'relative' }}>
            <div style={{
              width: 56, height: 56, borderRadius: 16,
              background: hasLogo ? '#fff' : 'oklch(1 0 0 / 0.16)',
              border: '1px solid oklch(1 0 0 / 0.32)', boxShadow: '0 8px 20px -6px oklch(0 0 0 / 0.35)',
              display: 'grid', placeItems: 'center', marginBottom: 26, overflow: 'hidden', padding: hasLogo ? 8 : 0,
            }}>
              {hasLogo
                ? <img src={company.logoUrl} alt="" style={{ width: '100%', height: '100%', objectFit: 'contain' }} onError={() => setHasLogo(false)} />
                : <img src={company.logoUrl} alt="" style={{ display: 'none' }} onLoad={() => setHasLogo(true)} onError={() => setHasLogo(false)} />}
              {!hasLogo && <Icon n="wrench" s={24} />}
            </div>
            <div style={{ fontFamily: 'Saira', fontWeight: 800, fontSize: 27, lineHeight: 1.18, marginBottom: 10, maxWidth: 340 }}>
              {brand.name}
            </div>
            <div style={{ fontSize: 13.5, opacity: 0.85, maxWidth: 320 }}>
              {brand.tagline || 'One workspace for stock, sales, and reporting across your whole distribution network.'}
            </div>
          </div>

          <div className="col gap-4" style={{ position: 'relative' }}>
            {FEATURES.map((f) => (
              <div key={f.label} className="row gap-3" style={{ alignItems: 'flex-start' }}>
                <div style={{
                  width: 30, height: 30, borderRadius: 9, background: 'oklch(1 0 0 / 0.14)',
                  border: '1px solid oklch(1 0 0 / 0.28)', display: 'grid', placeItems: 'center', flexShrink: 0,
                }}>
                  <Icon n={f.icon} s={15} />
                </div>
                <div style={{ fontSize: 13, opacity: 0.9, paddingTop: 5 }}>{f.label}</div>
              </div>
            ))}
          </div>
        </div>

        <form onSubmit={submit} style={{
          padding: '48px 44px', display: 'flex', flexDirection: 'column', gap: 20, justifyContent: 'center',
        }}>
          <div>
            <div style={{ fontFamily: 'Saira', fontWeight: 800, fontSize: 23, marginBottom: 5 }}>Welcome back</div>
            <div className="t-2" style={{ fontSize: 13.5 }}>Sign in to continue to your dashboard</div>
          </div>

          <label style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
            <span className="eyebrow" style={{ fontSize: 10 }}>Email</span>
            <div style={{ position: 'relative' }}>
              <Icon n="mail" s={16} style={{ position: 'absolute', top: '50%', left: 13, transform: 'translateY(-50%)', color: 'var(--tx-2)' }} />
              <input
                className="login-input"
                style={input}
                type="email"
                autoComplete="username"
                placeholder="you@company.com"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                required
                autoFocus
              />
            </div>
          </label>

          <label style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
            <span className="eyebrow" style={{ fontSize: 10 }}>Password</span>
            <div style={{ position: 'relative' }}>
              <Icon n="shield" s={16} style={{ position: 'absolute', top: '50%', left: 13, transform: 'translateY(-50%)', color: 'var(--tx-2)' }} />
              <input
                className="login-input"
                style={{ ...input, paddingRight: 42 }}
                type={showPw ? 'text' : 'password'}
                autoComplete="current-password"
                placeholder="••••••••"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                required
              />
              <button
                type="button"
                onClick={() => setShowPw((v) => !v)}
                aria-label={showPw ? 'Hide password' : 'Show password'}
                title={showPw ? 'Hide password' : 'Show password'}
                style={{
                  position: 'absolute', top: '50%', right: 8, transform: 'translateY(-50%)',
                  background: 'none', border: 'none', cursor: 'pointer', padding: 6,
                  color: 'var(--tx-2)', display: 'grid', placeItems: 'center',
                }}
              >
                <Icon n={showPw ? 'eye-off' : 'eye'} s={18} />
              </button>
            </div>
          </label>

          {err && (
            <div style={{
              background: 'var(--bad-dim)', color: 'var(--bad)', border: '1px solid var(--bad)',
              padding: '8px 12px', borderRadius: 'var(--r-s)', fontSize: 12.5,
            }}>{err}</div>
          )}

          <Btn variant="primary" size="lg" icon="shield" disabled={busy} style={{ marginTop: 4 }}>
            {busy ? 'Signing in...' : 'Sign in'}
          </Btn>
        </form>
      </div>
    </div>
  )
}
