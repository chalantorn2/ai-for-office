import { useEffect, useState } from 'react'
import { Loader2 } from 'lucide-react'
import { api } from '@/lib/api'

const baht = (n) =>
  `฿${Number(n ?? 0).toLocaleString('th-TH', { maximumFractionDigits: 2 })}`

const num = (n) => Number(n ?? 0).toLocaleString('th-TH')

/**
 * What Nova has cost this month.
 *
 * The office runs to a budget and until now had no way to see the bill before
 * it arrived. Admins additionally get the office total and a per-person
 * breakdown; everyone else sees their own figures, which is the part they can
 * act on.
 */
export function UsagePanel({ user }) {
  const [data, setData] = useState(null)
  const [error, setError] = useState(null)

  useEffect(() => {
    let cancelled = false

    api
      .usage(user.role === 'admin' ? 'all' : undefined)
      .then((d) => !cancelled && setData(d))
      .catch((e) => !cancelled && setError(e.message || 'โหลดข้อมูลไม่สำเร็จ'))

    return () => {
      cancelled = true
    }
  }, [user.role])

  if (error) {
    return <p className="p-4 text-sm text-destructive">{error}</p>
  }

  if (!data) {
    return (
      <div className="flex items-center justify-center p-8">
        <Loader2 className="size-5 animate-spin text-muted-foreground" />
      </div>
    )
  }

  const office = data.office
  const budget = data.budget.monthly_thb
  const spent = office?.cost_thb ?? data.me.cost_thb
  const remaining = Math.max(0, data.budget.daily_per_user - data.me.messages_today)

  return (
    <div className="flex flex-col gap-6 p-4 text-sm">
      <section>
        <h3 className="mb-2 font-medium">ของคุณ — เดือน {data.month}</h3>
        <dl className="space-y-1.5 text-muted-foreground">
          <Row label="คำตอบทั้งหมด" value={`${num(data.me.turns)} ครั้ง`} />
          <Row label="ค่าใช้จ่าย" value={baht(data.me.cost_thb)} />
          <Row
            label="วันนี้ถามไปแล้ว"
            value={`${num(data.me.messages_today)} / ${num(data.budget.daily_per_user)} คำถาม`}
          />
          {data.me.web_searches > 0 && (
            <Row label="ค้นเว็บ" value={`${num(data.me.web_searches)} ครั้ง`} />
          )}
        </dl>
        {remaining <= 10 && (
          <p className="mt-2 text-xs text-amber-600 dark:text-amber-500">
            วันนี้เหลืออีก {num(remaining)} คำถาม
          </p>
        )}
      </section>

      {office && (
        <section>
          <h3 className="mb-2 font-medium">ทั้งออฟฟิศ</h3>
          <dl className="space-y-1.5 text-muted-foreground">
            <Row label="คำตอบทั้งหมด" value={`${num(office.turns)} ครั้ง`} />
            <Row label="ค่าใช้จ่าย" value={baht(office.cost_thb)} />
            <Row label="เพดานเดือนนี้" value={baht(budget)} />
          </dl>

          {/* The bar is the whole point of showing an admin this panel: a number
              next to a budget still has to be divided in your head. */}
          <div className="mt-3 h-1.5 overflow-hidden rounded-full bg-muted">
            <div
              className={
                spent / budget > 0.85 ? 'h-full bg-destructive' : 'h-full bg-primary'
              }
              style={{ width: `${Math.min(100, (spent / budget) * 100)}%` }}
            />
          </div>
        </section>
      )}

      {data.by_user?.length > 0 && (
        <section>
          <h3 className="mb-2 font-medium">แยกตามคน</h3>
          <table className="w-full text-muted-foreground">
            <tbody>
              {data.by_user.map((u) => (
                <tr key={u.username} className="border-b last:border-0">
                  <td className="py-1.5 pr-2">{u.name ?? u.username}</td>
                  <td className="py-1.5 text-right tabular-nums">{num(u.turns)}</td>
                  <td className="py-1.5 text-right tabular-nums">{baht(u.cost_thb)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </section>
      )}

      <p className="text-xs text-muted-foreground">
        คิดจาก token ที่ใช้จริง รวมค่า cache และค่าค้นเว็บ เป็นราคาประมาณ ไม่ใช่ยอดจาก Anthropic
      </p>
    </div>
  )
}

function Row({ label, value }) {
  return (
    <div className="flex justify-between gap-4">
      <dt>{label}</dt>
      <dd className="tabular-nums text-foreground">{value}</dd>
    </div>
  )
}
