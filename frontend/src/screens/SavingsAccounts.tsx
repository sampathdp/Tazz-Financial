/* Daily Savings — Accounts: open, pass-book (ledger), deposit / withdraw / close. */
import { useEffect, useState } from 'react'
import { Card, PageHead, Btn, Badge, Field, Input, DateInput, Select, BankSelect, SearchSelect, Modal, Table, Td, inputStyle, ErrorBanner, LoadingBlock , BankAccountSelect } from '../components/ui'
import { Icon } from '../components/Icon'
import {
  savingsAccountsApi, savingsProductsApi, branchesApi, customersApi,
  type SavingsAccount, type SavingsTransaction, type SavingsProduct, type SavingsPaymentMethod,
  type SavingsAccountSummary, type Branch, type Customer, type AuthUser,
} from '../api'
import { Can, userCan } from '../lib/permissions'
import { AdminEditButton } from '../components/AdminEditButton'
import { useNotify } from '../components/Notify'
import { getErrorText } from '../lib/apiError'
import type { Go } from './types'

const money = (v: number | string | null | undefined) =>
  Number(v ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const dateOnly = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 10) : '—')
const todayIso = () => new Date().toISOString().slice(0, 10)

const METHODS: { value: SavingsPaymentMethod; label: string }[] = [
  { value: 'Cash', label: 'Cash' }, { value: 'Cheque', label: 'Cheque' }, { value: 'Bank', label: 'Bank Transfer' },
]
const statusTone = (s: string) => s === 'Active' ? 'green' : s === 'Dormant' ? 'amber' : s === 'Frozen' ? 'blue' : 'neutral'

type ActionKind = 'deposit' | 'withdraw' | 'close' | null

export function SavingsAccountsScreen({ user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [rows, setRows] = useState<SavingsAccount[]>([])
  const [products, setProducts] = useState<SavingsProduct[]>([])
  const [branches, setBranches] = useState<Branch[]>([])
  const [customers, setCustomers] = useState<Customer[]>([])
  const [loading, setLoading] = useState(true)
  const [tab, setTab] = useState<'all' | 'Active' | 'Dormant' | 'Frozen' | 'Closed'>('Active')

  const [selected, setSelected] = useState<SavingsAccount | null>(null)
  const [txns, setTxns] = useState<SavingsTransaction[]>([])
  const [detailLoading, setDetailLoading] = useState(false)

  const [openModal, setOpenModal] = useState(false)
  const [action, setAction] = useState<ActionKind>(null)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  const refresh = () => {
    setLoading(true)
    savingsAccountsApi.list(tab === 'all' ? undefined : { status: tab }).then(setRows).catch(() => setRows([])).finally(() => setLoading(false))
  }
  useEffect(() => { refresh() }, [tab]) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => {
    savingsProductsApi.list().then(setProducts).catch(() => setProducts([]))
    branchesApi.list().then(setBranches).catch(() => setBranches([]))
    customersApi.list().then(setCustomers).catch(() => setCustomers([]))
  }, [])

  const [summary, setSummary] = useState<SavingsAccountSummary | null>(null)
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [hideVoided, setHideVoided] = useState(false)

  const loadDetail = (id: number, range?: { from?: string; to?: string }) => {
    const f = range ? range.from : from
    const t = range ? range.to : to
    setDetailLoading(true)
    savingsAccountsApi.statement(id, { from: f || undefined, to: t || undefined })
      .then((d) => { setSelected(d.account); setTxns(d.transactions); setSummary(d.summary) })
      .catch((ex) => notify.error('Load failed', getErrorText(ex))).finally(() => setDetailLoading(false))
  }
  const openDetail = (id: number) => { setFrom(''); setTo(''); setHideVoided(false); loadDetail(id, {}) }

  // ----- open account form -----
  const [openForm, setOpenForm] = useState({
    branchId: undefined as number | undefined, customerId: undefined as number | undefined, customerLabel: '',
    savingsProductId: undefined as number | undefined, openDate: todayIso(), openingDeposit: '',
    paymentMethod: 'Cash' as SavingsPaymentMethod, referenceNo: '', bankId: undefined as number | undefined, bankAccountId: undefined as number | undefined, notes: '',
  })
  const startOpen = () => {
    setOpenForm({ branchId: branches[0]?.id, customerId: undefined, customerLabel: '', savingsProductId: products[0]?.id, openDate: todayIso(), openingDeposit: '', paymentMethod: 'Cash', referenceNo: '', bankId: undefined, bankAccountId: undefined, notes: '' })
    setErr(''); setOpenModal(true)
  }
  const submitOpen = async () => {
    if (!openForm.branchId || !openForm.customerId || !openForm.savingsProductId) { setErr('Branch, customer and product are required.'); return }
    if (openForm.openingDeposit === '' || Number(openForm.openingDeposit) < 0) { setErr('Enter an opening deposit (0 or more).'); return }
    if (openForm.paymentMethod !== 'Cash' && !openForm.bankId) { setErr('Bank is required for Cheque / Bank Transfer.'); return }
    setBusy(true); setErr('')
    try {
      const acct = await savingsAccountsApi.open({
        branchId: openForm.branchId, customerId: openForm.customerId, savingsProductId: openForm.savingsProductId,
        openDate: openForm.openDate, openingDeposit: openForm.openingDeposit, paymentMethod: openForm.paymentMethod,
        referenceNo: openForm.paymentMethod !== 'Cash' ? openForm.referenceNo || undefined : undefined,
        bankId: openForm.paymentMethod !== 'Cash' ? openForm.bankId : undefined, bankAccountId: openForm.paymentMethod !== 'Cash' ? openForm.bankAccountId : undefined, notes: openForm.notes || undefined,
      })
      setOpenModal(false); refresh(); notify.success('Account opened', acct.accountNumber)
      openDetail(acct.id)
    } catch (ex: any) { setErr(getErrorText(ex, 'Failed to open account')) } finally { setBusy(false) }
  }

  // ----- deposit / withdraw / close form -----
  const [actForm, setActForm] = useState({ amount: '', txnDate: todayIso(), paymentMethod: 'Cash' as SavingsPaymentMethod, referenceNo: '', bankId: undefined as number | undefined, bankAccountId: undefined as number | undefined, notes: '' })
  const startAction = (kind: ActionKind) => { setActForm({ amount: '', txnDate: todayIso(), paymentMethod: 'Cash', referenceNo: '', bankId: undefined, bankAccountId: undefined, notes: '' }); setErr(''); setAction(kind) }
  const submitAction = async () => {
    if (!selected || !action) return
    if (action !== 'close' && (!actForm.amount || Number(actForm.amount) <= 0)) { setErr('Enter an amount greater than zero.'); return }
    if (action === 'deposit' && actForm.paymentMethod !== 'Cash' && !actForm.bankId) { setErr('Bank is required for Cheque / Bank Transfer.'); return }
    if (action !== 'deposit' && actForm.paymentMethod !== 'Cash' && !actForm.bankAccountId) { setErr('Choose the company bank account this payment is made from.'); return }
    setBusy(true); setErr('')
    try {
      if (action === 'deposit') await savingsAccountsApi.deposit(selected.id, { amount: actForm.amount, txnDate: actForm.txnDate, paymentMethod: actForm.paymentMethod, referenceNo: actForm.paymentMethod !== 'Cash' ? actForm.referenceNo || undefined : undefined, bankId: actForm.paymentMethod !== 'Cash' ? actForm.bankId : undefined, notes: actForm.notes || undefined })
      else if (action === 'withdraw') await savingsAccountsApi.withdraw(selected.id, { amount: actForm.amount, txnDate: actForm.txnDate, paymentMethod: actForm.paymentMethod, bankAccountId: actForm.paymentMethod !== 'Cash' ? actForm.bankAccountId : undefined, referenceNo: actForm.referenceNo || undefined, notes: actForm.notes || undefined })
      else await savingsAccountsApi.close(selected.id, { txnDate: actForm.txnDate, paymentMethod: actForm.paymentMethod, bankAccountId: actForm.paymentMethod !== 'Cash' ? actForm.bankAccountId : undefined, referenceNo: actForm.referenceNo || undefined, notes: actForm.notes || undefined })
      setAction(null); refresh(); loadDetail(selected.id)
      notify.success(action === 'deposit' ? 'Deposit recorded' : action === 'withdraw' ? 'Withdrawal recorded' : 'Account closed', selected.accountNumber)
    } catch (ex: any) { setErr(getErrorText(ex, 'Action failed')) } finally { setBusy(false) }
  }

  const setDormancy = async (s: 'Active' | 'Dormant' | 'Frozen') => {
    if (!selected) return
    try { const a = await savingsAccountsApi.setStatus(selected.id, s); setSelected(a); refresh(); notify.success('Status updated', s) }
    catch (ex: any) { notify.error('Failed', getErrorText(ex)) }
  }

  const voidTxn = async (t: SavingsTransaction) => {
    if (!selected) return
    const ok = await notify.confirm({ title: `Void transaction ${t.txnNumber}?`, msg: 'Reverses its ledger entry and walks the balance back. Only the latest transaction can be voided.', danger: true, okText: 'Void' })
    if (!ok) return
    try { await savingsAccountsApi.voidTransaction(t.id); loadDetail(selected.id); refresh(); notify.success('Transaction voided', t.txnNumber) }
    catch (ex: any) { notify.error('Void failed', getErrorText(ex)) }
  }

  // ---------- detail view ----------
  if (selected) {
    const canEdit = userCan(me, 'Savings Accounts', 'Edit')
    const visibleTxns = hideVoided ? txns.filter((t) => t.status !== 'Voided') : txns
    return (
      <div>
        <div className="row gap-2 no-print" style={{ marginBottom: 16 }}>
          <Btn variant="solid" icon="chev" onClick={() => { setSelected(null); setTxns([]) }}>Back</Btn>
          {selected.status !== 'Closed' && (
            <AdminEditButton user={me} title={`Edit ${selected.accountNumber}`} values={{ ...selected }}
              hint="Notes can always be changed. Rate and fee terms are blocked once the account has any movement beyond the opening deposit."
              fields={[
                { key: 'interestRatePercent', label: 'Interest Rate %', type: 'number', step: '0.001' },
                { key: 'minBalance', label: 'Minimum Balance', type: 'number', step: '0.01' },
                { key: 'withdrawalFee', label: 'Withdrawal Fee', type: 'number', step: '0.01' },
                { key: 'notes', label: 'Notes', type: 'textarea' },
              ]}
              onSave={(v) => savingsAccountsApi.adminUpdate(selected.id, v)} onSaved={(a) => { setSelected(a); refresh() }} />
          )}
          {canEdit && selected.status !== 'Closed' && <>
            <Btn variant="primary" icon="plus" onClick={() => startAction('deposit')}>Deposit</Btn>
            <Btn variant="solid" icon="receipt" onClick={() => startAction('withdraw')} disabled={selected.status === 'Frozen'}>Withdraw</Btn>
            <Btn variant="solid" icon="x" onClick={() => startAction('close')}>Close</Btn>
            {selected.status === 'Frozen'
              ? <Btn variant="ghost" onClick={() => setDormancy('Active')}>Unfreeze</Btn>
              : <Btn variant="ghost" onClick={() => setDormancy('Frozen')}>Freeze</Btn>}
          </>}
        </div>

        <Card style={{ marginBottom: 16, background: 'linear-gradient(135deg, var(--bg-1) 55%, var(--ac-dim))' }}>
          <div className="row between wrap" style={{ gap: 20, alignItems: 'center' }}>
            <div className="row gap-3" style={{ alignItems: 'center' }}>
              <div style={{ width: 52, height: 52, borderRadius: '50%', background: 'var(--ac-dim)', color: 'var(--ac-bright)', display: 'grid', placeItems: 'center', fontFamily: 'Saira', fontWeight: 800, fontSize: 20, flexShrink: 0 }}>
                {(selected.customer?.fullName || '?').replace(/^(mr|mrs|ms|miss|dr)\.?\s+/i, '').trim().charAt(0).toUpperCase()}
              </div>
              <div>
                <div className="row gap-2 wrap" style={{ alignItems: 'center' }}>
                  <span style={{ fontFamily: 'Saira', fontWeight: 800, fontSize: 20 }}>{selected.customer?.fullName}</span>
                  <Badge tone={statusTone(selected.status) as any} dot>{selected.status}</Badge>
                </div>
                <div className="t-2" style={{ fontSize: 13, marginTop: 2 }}>
                  <span style={{ fontFamily: 'JetBrains Mono' }}>{selected.accountNumber}</span> · {selected.savingsProduct?.name} · {selected.branch?.name}
                </div>
              </div>
            </div>
            <div className="row gap-5" style={{ textAlign: 'right' }}>
              <div>
                <div className="eyebrow">Current Balance</div>
                <div style={{ fontFamily: 'JetBrains Mono', fontSize: 30, fontWeight: 800, lineHeight: 1.15 }}>{money(selected.currentBalance)}</div>
              </div>
              <div style={{ paddingLeft: 20, borderLeft: '1px solid var(--line)' }}>
                <div className="eyebrow">Available</div>
                <div style={{ fontFamily: 'JetBrains Mono', fontSize: 20, fontWeight: 700, color: 'var(--ok)', lineHeight: 1.6 }}>{money(selected.availableBalance ?? (Number(selected.currentBalance) - Number(selected.minBalance)))}</div>
              </div>
            </div>
          </div>
        </Card>

        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(190px, 1fr))', gap: 14, marginBottom: 16 }}>
          {([
            ['Total Deposits', summary?.totalDeposits, 'var(--ok)', `${summary?.collectionDeposits ?? 0} field collections`],
            ['Total Withdrawals', summary?.totalWithdrawals, 'var(--bad)', 'paid out'],
            ['Interest Credited', summary?.totalInterest, 'var(--ac-bright)', `accrued ${money(selected.interestAccrued)}`],
            ['Fees Charged', summary?.totalFees, 'var(--warn)', `${summary?.transactionCount ?? 0} transactions`],
          ] as [string, number | undefined, string, string][]).map(([label, v, color, hint]) => (
            <div key={label} style={{ background: 'var(--bg-1)', border: '1px solid var(--line)', borderLeft: `4px solid ${color}`, borderRadius: 'var(--r-l)', padding: '14px 16px', boxShadow: 'var(--sh-1)' }}>
              <div className="eyebrow">{label}</div>
              <div style={{ fontFamily: 'JetBrains Mono', fontSize: 22, fontWeight: 700, margin: '4px 0 2px' }}>{money(v)}</div>
              <div className="t-2" style={{ fontSize: 11.5 }}>{hint}</div>
            </div>
          ))}
        </div>

        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 14, marginBottom: 16 }}>
          <Card>
            <div style={{ fontWeight: 600, marginBottom: 12 }}>Account Terms</div>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '12px 20px' }}>
              {([
                ['Interest rate', `${Number(selected.interestRatePercent).toFixed(2)}% p.a.`],
                ['Calculation basis', selected.interestCalcMethod.replace(/_/g, ' ')],
                ['Posting', selected.interestPostingFrequency],
                ['Last interest posted', dateOnly(selected.lastInterestPostedOn)],
                ['Minimum balance', money(selected.minBalance)],
                ['Withdrawal fee', money(selected.withdrawalFee)],
                ['Opened', dateOnly(selected.openDate)],
                [selected.closedOn ? 'Closed' : 'Last activity', dateOnly(selected.closedOn ?? selected.lastActivityOn ?? summary?.lastTxnDate)],
              ] as [string, string][]).map(([k, v]) => (
                <div key={k}><div className="eyebrow">{k}</div><div style={{ fontWeight: 600, fontSize: 13.5, textTransform: 'capitalize' }}>{v}</div></div>
              ))}
            </div>
            {selected.notes && <div className="t-2" style={{ fontSize: 12.5, marginTop: 14, paddingTop: 12, borderTop: '1px solid var(--line)' }}>{selected.notes}</div>}
          </Card>
          <Card>
            <div style={{ fontWeight: 600, marginBottom: 12 }}>Customer</div>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '12px 20px' }}>
              {([
                ['Code', selected.customer?.code], ['NIC', selected.customer?.nicNo], ['Phone', selected.customer?.phone],
                ['Email', selected.customer?.email], ['Opened by', selected.creator?.name],
                ['First transaction', dateOnly(summary?.firstTxnDate)],
              ] as [string, string | null | undefined][]).map(([k, v]) => (
                <div key={k}><div className="eyebrow">{k}</div><div style={{ fontWeight: 600, fontSize: 13.5, wordBreak: 'break-word' }}>{v || '—'}</div></div>
              ))}
            </div>
            {selected.customer?.address && <div className="t-2" style={{ fontSize: 12.5, marginTop: 14, paddingTop: 12, borderTop: '1px solid var(--line)' }}>{selected.customer.address}</div>}
          </Card>
        </div>

        <Card pad={0}>
          <div className="row between wrap" style={{ padding: '12px 16px', gap: 12 }}>
            <div style={{ fontWeight: 600 }}>Pass-book <span className="t-2" style={{ fontWeight: 400, fontSize: 12 }}>({visibleTxns.length} entries)</span></div>
            <div className="row gap-2 wrap no-print" style={{ alignItems: 'flex-end' }}>
              <div style={{ width: 160 }}><Field label="From"><DateInput value={from} onChange={setFrom} /></Field></div>
              <div style={{ width: 160 }}><Field label="To"><DateInput value={to} onChange={setTo} /></Field></div>
              <Btn size="sm" variant="solid" onClick={() => loadDetail(selected.id)}>Filter</Btn>
              {(from || to) && <Btn size="sm" variant="ghost" onClick={() => { setFrom(''); setTo(''); loadDetail(selected.id, {}) }}>Clear</Btn>}
              <label className="row gap-1 t-2" style={{ fontSize: 12, cursor: 'pointer' }}>
                <input type="checkbox" checked={hideVoided} onChange={(e) => setHideVoided(e.target.checked)} /> Hide voided
              </label>
              <Btn size="sm" variant="ghost" icon="receipt" onClick={() => window.print()}>Print</Btn>
            </div>
          </div>
          {detailLoading ? <LoadingBlock /> :
          <Table cols={[{ label: 'Txn No' }, { label: 'Date' }, { label: 'Type' }, { label: 'Method' }, { label: 'Reference' }, { label: 'By' }, { label: 'In', align: 'right' }, { label: 'Out', align: 'right' }, { label: 'Balance', align: 'right' }, { label: '', align: 'right', w: 60 }]}
            rows={visibleTxns} empty="No transactions yet"
            render={(t, i) => <>
              <Td mono>{t.txnNumber}</Td>
              <Td>{dateOnly(t.txnDate)}</Td>
              <Td>
                <Badge tone={(t.type === 'Deposit' || t.type === 'OpeningBalance' ? 'green' : t.type === 'InterestPosting' ? 'blue' : t.type === 'Fee' ? 'amber' : t.type === 'Withdrawal' ? 'red' : 'neutral') as any}>{t.type === 'InterestPosting' ? 'Interest' : t.type}</Badge>
                {t.status === 'Voided' && <span style={{ marginLeft: 6 }}><Badge tone="red">Voided</Badge></span>}
                {t.notes && <div className="t-2" style={{ fontSize: 11 }}>{t.notes}</div>}
              </Td>
              <Td>{t.paymentMethod || '—'}{t.bank?.name ? ` · ${t.bank.name}` : ''}</Td>
              <Td>{[t.referenceNo, t.collectionSheet?.sheetNumber, t.interestRun?.runNumber].filter(Boolean).join(' · ') || '—'}</Td>
              <Td>{t.creator?.name || '—'}</Td>
              <Td align="right" mono><span style={{ color: t.direction === 'In' ? 'var(--ok)' : undefined, fontWeight: 600 }}>{t.direction === 'In' ? '+' + money(t.amount) : '—'}</span></Td>
              <Td align="right" mono><span style={{ color: t.direction === 'Out' ? 'var(--bad)' : undefined, fontWeight: 600 }}>{t.direction === 'Out' ? '−' + money(t.amount) : '—'}</span></Td>
              <Td align="right" mono><b>{money(t.runningBalance)}</b></Td>
              <Td align="right">{i === 0 && !from && !to && !hideVoided && t.status === 'Posted' && t.type !== 'InterestPosting' && canEdit && (
                <button className="mms-act" title="Void" onClick={() => voidTxn(t)}><Icon n="trash" s={14} /></button>
              )}</Td>
            </>} />}
        </Card>

        <Modal open={!!action} onClose={() => setAction(null)} width={480}
          title={action === 'deposit' ? 'Record Deposit' : action === 'withdraw' ? 'Record Withdrawal' : 'Close Account'}
          footer={<><Btn variant="plain" onClick={() => setAction(null)}>Cancel</Btn><Btn variant="primary" icon="check" onClick={submitAction} disabled={busy}>{busy ? 'Working…' : 'Confirm'}</Btn></>}>
          <ErrorBanner error={err} style={{ marginBottom: 14 }} />
          {action === 'close' && <div className="t-2" style={{ fontSize: 12.5, marginBottom: 12 }}>The full balance of <b>{money(selected.currentBalance)}</b> will be paid out and the account closed. Needs an authorised approver.</div>}
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 14 }}>
            {action !== 'close' && <Field label="Amount"><Input type="number" step="0.01" value={actForm.amount} onChange={(e) => setActForm((s) => ({ ...s, amount: e.target.value }))} /></Field>}
            <Field label="Date"><DateInput value={actForm.txnDate} onChange={(iso) => setActForm((s) => ({ ...s, txnDate: iso }))} /></Field>
            <Field label="Payment Method"><Select value={actForm.paymentMethod} onChange={(e) => setActForm((s) => ({ ...s, paymentMethod: e.target.value as SavingsPaymentMethod }))}>
              {METHODS.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
            </Select></Field>
            {actForm.paymentMethod !== 'Cash' && <Field label="Reference"><Input value={actForm.referenceNo} onChange={(e) => setActForm((s) => ({ ...s, referenceNo: e.target.value }))} /></Field>}
            {actForm.paymentMethod !== 'Cash' && <Field label={action === 'deposit' ? 'Received into — company bank account (optional)' : 'Paid from — company bank account'}><BankAccountSelect value={actForm.bankAccountId} onChange={(v) => setActForm((s) => ({ ...s, bankAccountId: v }))} /></Field>}
            {action === 'deposit' && actForm.paymentMethod !== 'Cash' && <Field label="Bank"><BankSelect value={actForm.bankId} onChange={(v) => setActForm((s) => ({ ...s, bankId: v }))} /></Field>}
            <Field label="Notes" full><textarea value={actForm.notes} onChange={(e) => setActForm((s) => ({ ...s, notes: e.target.value }))} rows={2} style={{ ...inputStyle, resize: 'vertical' }} /></Field>
          </div>
        </Modal>
      </div>
    )
  }

  // ---------- list view ----------
  return (
    <div>
      <PageHead crumbs="Funding / Investment" title="Savings Accounts" icon="wallet" sub={`${rows.length} accounts`}
        actions={<Can user={me} module="Savings Accounts" action="Create"><Btn variant="primary" icon="plus" onClick={startOpen}>Open Account</Btn></Can>} />
      <Card pad={0}>
        <div className="row gap-1 wrap" style={{ padding: '14px 16px 0' }}>
          {(['Active', 'Dormant', 'Frozen', 'Closed', 'all'] as const).map((id) => (
            <Btn key={id} size="sm" variant="ghost" active={tab === id} onClick={() => setTab(id)}>{id === 'all' ? 'All' : id}</Btn>
          ))}
        </div>
        {loading ? <LoadingBlock /> :
        <Table cols={[{ label: 'Account No' }, { label: 'Customer' }, { label: 'Product' }, { label: 'Opened' }, { label: 'Balance', align: 'right' }, { label: 'Status', align: 'center' }]}
          rows={rows}
          render={(a) => <>
            <Td mono><button className="mms-linkbtn" onClick={() => openDetail(a.id)} style={{ background: 'none', border: 'none', color: 'var(--ac-bright)', cursor: 'pointer', padding: 0 }}>{a.accountNumber}</button></Td>
            <Td>{a.customer?.fullName || '—'}</Td>
            <Td>{a.savingsProduct?.name || '—'}</Td>
            <Td>{dateOnly(a.openDate)}</Td>
            <Td align="right" mono>{money(a.currentBalance)}</Td>
            <Td align="center"><Badge tone={statusTone(a.status) as any} dot>{a.status}</Badge></Td>
          </>} />}
      </Card>

      <Modal open={openModal} onClose={() => setOpenModal(false)} width={560} title="Open Savings Account"
        footer={<><Btn variant="plain" onClick={() => setOpenModal(false)}>Cancel</Btn><Btn variant="primary" icon="check" onClick={submitOpen} disabled={busy}>{busy ? 'Opening…' : 'Open Account'}</Btn></>}>
        <ErrorBanner error={err} style={{ marginBottom: 14 }} />
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
          <Field label="Customer" full>
            <SearchSelect value={openForm.customerLabel} onChange={(name, opt: Customer | undefined) => setOpenForm((s) => ({ ...s, customerId: opt?.id, customerLabel: name }))}
              options={customers} getLabel={(c: Customer) => `${c.code} — ${c.fullName}`} getSub={(c: Customer) => [c.nicNo, c.phone].filter(Boolean).join(' · ')} placeholder="Search by NIC, code, or name…" />
          </Field>
          <Field label="Branch"><Select value={openForm.branchId ?? ''} onChange={(e) => setOpenForm((s) => ({ ...s, branchId: Number(e.target.value) || undefined }))}>
            <option value="">—</option>{branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
          </Select></Field>
          <Field label="Product"><Select value={openForm.savingsProductId ?? ''} onChange={(e) => setOpenForm((s) => ({ ...s, savingsProductId: Number(e.target.value) || undefined }))}>
            <option value="">—</option>{products.filter((p) => (p.status || 'Active') === 'Active').map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
          </Select></Field>
          <Field label="Open Date"><DateInput value={openForm.openDate} onChange={(iso) => setOpenForm((s) => ({ ...s, openDate: iso }))} /></Field>
          <Field label="Opening Deposit"><Input type="number" step="0.01" value={openForm.openingDeposit} onChange={(e) => setOpenForm((s) => ({ ...s, openingDeposit: e.target.value }))} /></Field>
          <Field label="Payment Method"><Select value={openForm.paymentMethod} onChange={(e) => setOpenForm((s) => ({ ...s, paymentMethod: e.target.value as SavingsPaymentMethod }))}>
            {METHODS.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
          </Select></Field>
          {openForm.paymentMethod !== 'Cash' && <>
            <Field label="Reference"><Input value={openForm.referenceNo} onChange={(e) => setOpenForm((s) => ({ ...s, referenceNo: e.target.value }))} /></Field>
            <Field label="Bank"><BankSelect value={openForm.bankId} onChange={(v) => setOpenForm((s) => ({ ...s, bankId: v }))} /></Field>
            <Field label="Received into — company bank account (optional)"><BankAccountSelect value={openForm.bankAccountId} onChange={(v) => setOpenForm((s) => ({ ...s, bankAccountId: v }))} /></Field>
          </>}
          <Field label="Notes" full><textarea value={openForm.notes} onChange={(e) => setOpenForm((s) => ({ ...s, notes: e.target.value }))} rows={2} style={{ ...inputStyle, resize: 'vertical' }} /></Field>
        </div>
      </Modal>
    </div>
  )
}

export default SavingsAccountsScreen
