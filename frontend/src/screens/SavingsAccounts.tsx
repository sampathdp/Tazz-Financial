/* Daily Savings — Accounts: open, pass-book (ledger), deposit / withdraw / close. */
import { useEffect, useState } from 'react'
import { Card, PageHead, Btn, Badge, Field, Input, DateInput, Select, BankSelect, SearchSelect, Modal, Table, Td, inputStyle, ErrorBanner, LoadingBlock } from '../components/ui'
import { Icon } from '../components/Icon'
import {
  savingsAccountsApi, savingsProductsApi, branchesApi, customersApi,
  type SavingsAccount, type SavingsTransaction, type SavingsProduct, type SavingsPaymentMethod,
  type SavingsAccountSummary, type Branch, type Customer, type AuthUser,
} from '../api'
import { Can, userCan } from '../lib/permissions'
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
    paymentMethod: 'Cash' as SavingsPaymentMethod, referenceNo: '', bankId: undefined as number | undefined, notes: '',
  })
  const startOpen = () => {
    setOpenForm({ branchId: branches[0]?.id, customerId: undefined, customerLabel: '', savingsProductId: products[0]?.id, openDate: todayIso(), openingDeposit: '', paymentMethod: 'Cash', referenceNo: '', bankId: undefined, notes: '' })
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
        bankId: openForm.paymentMethod !== 'Cash' ? openForm.bankId : undefined, notes: openForm.notes || undefined,
      })
      setOpenModal(false); refresh(); notify.success('Account opened', acct.accountNumber)
      openDetail(acct.id)
    } catch (ex: any) { setErr(getErrorText(ex, 'Failed to open account')) } finally { setBusy(false) }
  }

  // ----- deposit / withdraw / close form -----
  const [actForm, setActForm] = useState({ amount: '', txnDate: todayIso(), paymentMethod: 'Cash' as SavingsPaymentMethod, referenceNo: '', bankId: undefined as number | undefined, notes: '' })
  const startAction = (kind: ActionKind) => { setActForm({ amount: '', txnDate: todayIso(), paymentMethod: 'Cash', referenceNo: '', bankId: undefined, notes: '' }); setErr(''); setAction(kind) }
  const submitAction = async () => {
    if (!selected || !action) return
    if (action !== 'close' && (!actForm.amount || Number(actForm.amount) <= 0)) { setErr('Enter an amount greater than zero.'); return }
    if (action === 'deposit' && actForm.paymentMethod !== 'Cash' && !actForm.bankId) { setErr('Bank is required for Cheque / Bank Transfer.'); return }
    setBusy(true); setErr('')
    try {
      if (action === 'deposit') await savingsAccountsApi.deposit(selected.id, { amount: actForm.amount, txnDate: actForm.txnDate, paymentMethod: actForm.paymentMethod, referenceNo: actForm.paymentMethod !== 'Cash' ? actForm.referenceNo || undefined : undefined, bankId: actForm.paymentMethod !== 'Cash' ? actForm.bankId : undefined, notes: actForm.notes || undefined })
      else if (action === 'withdraw') await savingsAccountsApi.withdraw(selected.id, { amount: actForm.amount, txnDate: actForm.txnDate, paymentMethod: actForm.paymentMethod, referenceNo: actForm.referenceNo || undefined, notes: actForm.notes || undefined })
      else await savingsAccountsApi.close(selected.id, { txnDate: actForm.txnDate, paymentMethod: actForm.paymentMethod, referenceNo: actForm.referenceNo || undefined, notes: actForm.notes || undefined })
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
          {canEdit && selected.status !== 'Closed' && <>
            <Btn variant="primary" icon="plus" onClick={() => startAction('deposit')}>Deposit</Btn>
            <Btn variant="solid" icon="receipt" onClick={() => startAction('withdraw')} disabled={selected.status === 'Frozen'}>Withdraw</Btn>
            <Btn variant="solid" icon="x" onClick={() => startAction('close')}>Close</Btn>
            {selected.status === 'Frozen'
              ? <Btn variant="ghost" onClick={() => setDormancy('Active')}>Unfreeze</Btn>
              : <Btn variant="ghost" onClick={() => setDormancy('Frozen')}>Freeze</Btn>}
          </>}
        </div>

        <Card style={{ marginBottom: 16 }}>
          <div className="row between wrap" style={{ gap: 16 }}>
            <div>
              <div style={{ fontFamily: 'Saira', fontWeight: 800, fontSize: 18 }}>{selected.accountNumber}</div>
              <div className="t-2" style={{ fontSize: 13 }}>{selected.customer?.fullName} · {selected.savingsProduct?.name} · {selected.branch?.name}</div>
            </div>
            <div className="row gap-4" style={{ textAlign: 'right' }}>
              <div><div className="eyebrow">Balance</div><div style={{ fontFamily: 'JetBrains Mono', fontSize: 20, fontWeight: 700 }}>{money(selected.currentBalance)}</div></div>
              <div><div className="eyebrow">Available</div><div style={{ fontFamily: 'JetBrains Mono', fontSize: 15 }}>{money(selected.availableBalance ?? (Number(selected.currentBalance) - Number(selected.minBalance)))}</div></div>
              <div><div className="eyebrow">Status</div><Badge tone={statusTone(selected.status) as any} dot>{selected.status}</Badge></div>
            </div>
          </div>
          <div className="row gap-4 wrap t-2" style={{ fontSize: 12, marginTop: 14, paddingTop: 14, borderTop: '1px solid var(--line)' }}>
            <span>Rate: <b>{Number(selected.interestRatePercent).toFixed(2)}%</b></span>
            <span>Basis: <b>{selected.interestCalcMethod.replace(/_/g, ' ')}</b></span>
            <span>Posting: <b style={{ textTransform: 'capitalize' }}>{selected.interestPostingFrequency}</b></span>
            <span>Opened: <b>{dateOnly(selected.openDate)}</b></span>
            <span>Min balance: <b>{money(selected.minBalance)}</b></span>
            <span>Last interest: <b>{dateOnly(selected.lastInterestPostedOn)}</b></span>
            <span>Withdrawal fee: <b>{money(selected.withdrawalFee)}</b></span>
            <span>Last activity: <b>{dateOnly(selected.lastActivityOn ?? summary?.lastTxnDate)}</b></span>
            {selected.closedOn && <span>Closed: <b>{dateOnly(selected.closedOn)}</b></span>}
            {selected.creator && <span>Opened by: <b>{selected.creator.name}</b></span>}
          </div>
          {selected.notes && <div className="t-2" style={{ fontSize: 12.5, marginTop: 10 }}>Notes: {selected.notes}</div>}
        </Card>

        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: 16, marginBottom: 16 }}>
          <Card>
            <div className="eyebrow">Customer</div>
            <div style={{ fontWeight: 600, marginTop: 4 }}>{selected.customer?.fullName || '—'}</div>
            <div className="t-2" style={{ fontSize: 12.5, lineHeight: 1.6, marginTop: 4 }}>
              <div>Code: {selected.customer?.code || '—'}</div>
              <div>NIC: {selected.customer?.nicNo || '—'}</div>
              <div>Phone: {selected.customer?.phone || '—'}</div>
              {selected.customer?.email && <div>Email: {selected.customer.email}</div>}
              {selected.customer?.address && <div>Address: {selected.customer.address}</div>}
            </div>
          </Card>
          <Card>
            <div className="eyebrow">Lifetime Totals</div>
            <div style={{ fontSize: 13, lineHeight: 1.9, marginTop: 4 }}>
              {([
                ['Total deposits', summary?.totalDeposits], ['Total withdrawals', summary?.totalWithdrawals],
                ['Interest credited', summary?.totalInterest], ['Fees charged', summary?.totalFees],
              ] as [string, number | undefined][]).map(([label, v]) => (
                <div key={label} className="row between"><span className="t-2">{label}</span><b style={{ fontFamily: 'JetBrains Mono' }}>{money(v)}</b></div>
              ))}
            </div>
          </Card>
          <Card>
            <div className="eyebrow">Activity</div>
            <div style={{ fontSize: 13, lineHeight: 1.9, marginTop: 4 }}>
              <div className="row between"><span className="t-2">Transactions</span><b>{summary?.transactionCount ?? 0}</b></div>
              <div className="row between"><span className="t-2">Field collections</span><b>{summary?.collectionDeposits ?? 0}</b></div>
              <div className="row between"><span className="t-2">First transaction</span><b>{dateOnly(summary?.firstTxnDate)}</b></div>
              <div className="row between"><span className="t-2">Last transaction</span><b>{dateOnly(summary?.lastTxnDate)}</b></div>
              <div className="row between"><span className="t-2">Interest accrued</span><b style={{ fontFamily: 'JetBrains Mono' }}>{money(selected.interestAccrued)}</b></div>
            </div>
          </Card>
        </div>

        <Card pad={0}>
          <div className="row between wrap" style={{ padding: '12px 16px', gap: 12 }}>
            <div style={{ fontWeight: 600 }}>Pass-book <span className="t-2" style={{ fontWeight: 400, fontSize: 12 }}>({visibleTxns.length} entries)</span></div>
            <div className="row gap-2 wrap no-print" style={{ alignItems: 'flex-end' }}>
              <div style={{ width: 150 }}><DateInput value={from} onChange={setFrom} /></div>
              <div style={{ width: 150 }}><DateInput value={to} onChange={setTo} /></div>
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
                {t.type}{t.status === 'Voided' && <span style={{ marginLeft: 6 }}><Badge tone="red">Voided</Badge></span>}
                {t.notes && <div className="t-2" style={{ fontSize: 11 }}>{t.notes}</div>}
              </Td>
              <Td>{t.paymentMethod || '—'}{t.bank?.name ? ` · ${t.bank.name}` : ''}</Td>
              <Td>{[t.referenceNo, t.collectionSheet?.sheetNumber, t.interestRun?.runNumber].filter(Boolean).join(' · ') || '—'}</Td>
              <Td>{t.creator?.name || '—'}</Td>
              <Td align="right" mono>{t.direction === 'In' ? money(t.amount) : '—'}</Td>
              <Td align="right" mono>{t.direction === 'Out' ? money(t.amount) : '—'}</Td>
              <Td align="right" mono>{money(t.runningBalance)}</Td>
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
          </>}
          <Field label="Notes" full><textarea value={openForm.notes} onChange={(e) => setOpenForm((s) => ({ ...s, notes: e.target.value }))} rows={2} style={{ ...inputStyle, resize: 'vertical' }} /></Field>
        </div>
      </Modal>
    </div>
  )
}

export default SavingsAccountsScreen
