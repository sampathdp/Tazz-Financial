import { Component } from 'react'
import type { ErrorInfo, ReactNode } from 'react'

/** Catches render exceptions so one broken screen can't blank the whole app. */
export class ErrorBoundary extends Component<{ children: ReactNode }, { error: Error | null }> {
  state = { error: null as Error | null }

  static getDerivedStateFromError(error: Error) {
    return { error }
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    // console is stripped from prod builds; keep for dev diagnosis.
    console.error('[ErrorBoundary]', error, info.componentStack)
  }

  render() {
    if (!this.state.error) return this.props.children
    return (
      <div style={{ padding: '60px 20px', textAlign: 'center', color: 'var(--tx-2)' }}>
        <h2 style={{ fontSize: 18, color: 'var(--tx-0)', marginBottom: 6 }}>This screen hit a problem</h2>
        <p style={{ fontSize: 13, maxWidth: 420, margin: '0 auto 16px' }}>
          Your data on the server is safe. Try again, or reload the page if it keeps happening.
        </p>
        <button
          onClick={() => this.setState({ error: null })}
          style={{ padding: '8px 16px', borderRadius: 8, border: '1px solid var(--line)', background: 'var(--bg-2)', color: 'var(--tx-0)', cursor: 'pointer' }}
        >
          Try again
        </button>
      </div>
    )
  }
}
