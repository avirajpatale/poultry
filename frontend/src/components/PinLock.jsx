import { useState } from 'react'
import { useLang } from '../i18n.jsx'

/**
 * 4-digit app PIN gate. Stored on THIS device (localStorage 'farm_pin').
 * Convenience lock, not cryptographic security — clearing app data removes it.
 */
export default function PinLock({ onUnlock }) {
  const { t } = useLang()
  const [pin, setPin] = useState('')
  const [shake, setShake] = useState(false)

  const press = (d) => {
    const next = (pin + d).slice(0, 4)
    setPin(next)
    if (next.length === 4) {
      if (next === localStorage.getItem('farm_pin')) {
        onUnlock()
      } else {
        setShake(true)
        setTimeout(() => { setPin(''); setShake(false) }, 350)
      }
    }
  }

  return (
    <div style={{
      position: 'fixed', inset: 0, zIndex: 2000, display: 'flex',
      flexDirection: 'column', alignItems: 'center', justifyContent: 'center',
      background: 'linear-gradient(160deg,#14532d,#166534)', color: '#fff',
    }}>
      <div style={{ fontSize: '2.6rem' }}>🐔</div>
      <h5 className="mt-2 mb-3">{t('pin_enter')}</h5>
      <div className="d-flex gap-2 mb-4" style={shake ? { animation: 'pinshake .3s' } : {}}>
        {[0, 1, 2, 3].map((i) => (
          <div key={i} style={{
            width: 16, height: 16, borderRadius: '50%',
            background: pin.length > i ? '#fff' : 'rgba(255,255,255,.3)',
          }} />
        ))}
      </div>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3,72px)', gap: 12 }}>
        {[1, 2, 3, 4, 5, 6, 7, 8, 9, '', 0, '⌫'].map((d, i) => (
          d === '' ? <div key={i} /> : (
            <button key={i} type="button" onClick={() => d === '⌫' ? setPin(pin.slice(0, -1)) : press(String(d))}
              className="btn btn-lg"
              style={{
                background: 'rgba(255,255,255,.12)', color: '#fff', fontSize: '1.5rem',
                border: '1px solid rgba(255,255,255,.2)', borderRadius: 12, height: 64,
              }}>
              {d}
            </button>
          )
        ))}
      </div>
      <style>{`@keyframes pinshake{0%,100%{transform:translateX(0)}25%{transform:translateX(-8px)}75%{transform:translateX(8px)}}`}</style>
    </div>
  )
}
