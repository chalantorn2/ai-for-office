import { useState } from 'react'
import { AlertCircle, Loader2 } from 'lucide-react'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { NovaMark } from '@/components/nova-mark'
import { api, setToken } from '@/lib/api'

export function LoginPage({ onSuccess }) {
  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  async function handleSubmit(e) {
    e.preventDefault()
    if (busy) return

    setBusy(true)
    setError('')
    try {
      const { token, user } = await api.login(username.trim(), password)
      setToken(token)
      onSuccess(user)
    } catch (err) {
      setError(err.message || 'เข้าสู่ระบบไม่สำเร็จ')
      setPassword('')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="flex h-full items-center justify-center px-4">
      <div className="w-full max-w-sm">
        <div className="flex flex-col items-center text-center">
          <NovaMark className="size-10" />
          <h1 className="mt-4 text-2xl font-semibold tracking-tight">Nova</h1>
          <p className="mt-1.5 text-sm text-muted-foreground">
            ผู้ช่วยข้อมูลออฟฟิศ Seven Smile · INDO Smile
          </p>
        </div>

        <form onSubmit={handleSubmit} className="mt-8 space-y-4">
          <div className="space-y-2">
            <Label htmlFor="username">ชื่อผู้ใช้</Label>
            <Input
              id="username"
              value={username}
              onChange={(e) => setUsername(e.target.value)}
              autoComplete="username"
              autoFocus
              required
            />
          </div>

          <div className="space-y-2">
            <Label htmlFor="password">รหัสผ่าน</Label>
            <Input
              id="password"
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              autoComplete="current-password"
              required
            />
          </div>

          {error && (
            <Alert variant="destructive">
              <AlertCircle className="size-4" />
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}

          <Button type="submit" className="w-full" disabled={busy}>
            {busy && <Loader2 className="size-4 animate-spin" />}
            เข้าสู่ระบบ
          </Button>
        </form>

        <p className="mt-6 text-center text-xs text-muted-foreground">
          ใช้ชื่อผู้ใช้และรหัสผ่านเดียวกับระบบ ContactRate
        </p>
      </div>
    </div>
  )
}
