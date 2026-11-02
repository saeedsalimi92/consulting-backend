const assert = require('node:assert/strict')
const fs = require('node:fs')
const path = require('node:path')
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || '/tmp/elham-public-e2e/node_modules/playwright')

const base = process.env.PUBLIC_BASE_URL || 'http://localhost:3000'
const api = process.env.TEST_API_BASE || 'http://localhost:8011/api'
const screenshots = process.env.QA_SCREENSHOT_DIR || path.resolve(__dirname, '../storage/qa/public')
fs.mkdirSync(screenshots, { recursive: true })

let passed = 0
let failed = 0
const failures = []
const pageErrors = []
const apiResponses = []
let screenshotPage = null
let testEmail = ''
const testPassword = 'Qa-E2e-Test!2026'
const check = (condition, message) => assert.ok(condition, message)
const run = async (name, fn) => {
  try { await fn(); passed++; console.log(`PASS ${name}`) }
  catch (error) {
    failed++; failures.push({ name, error: String(error) }); console.log(`FAIL ${name}: ${error.message}`)
    if (screenshotPage) await screenshotPage.screenshot({ path: path.join(screenshots, `failure-${name.replace(/[^a-z0-9]+/gi, '-').toLowerCase()}.png`), fullPage: true }).catch(() => {})
  }
}
const tehranDate = (date) => new Intl.DateTimeFormat('en-CA', {
  timeZone: 'Asia/Tehran', year: 'numeric', month: '2-digit', day: '2-digit',
}).format(date)

async function main() {
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.CHROME_EXECUTABLE || '/tmp/elham-chrome/opt/google/chrome/chrome',
    args: ['--no-sandbox'],
  })
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } })
  const page = await context.newPage()
  screenshotPage = page
  page.on('pageerror', error => pageErrors.push(error.message))
  page.on('console', message => {
    if (message.type() === 'error' && !/status of 422 \(Unprocessable Content\)/.test(message.text())) pageErrors.push(message.text())
  })
  page.on('response', response => {
    if (response.url().startsWith(api)) apiResponses.push({ url: response.url(), status: response.status() })
  })

  const publicRoutes = [
    ['homepage', '/'], ['counselor listing', '/counselors'], ['service listing', '/service'],
    ['about', '/about'], ['contact', '/contact'], ['articles', '/blog'], ['FAQ', '/faq'],
    ['gallery', '/gallery'], ['booking', '/appointment'], ['login', '/account/login'],
  ]
  for (const [name, route] of publicRoutes) {
    await run(`desktop ${name}`, async () => {
      const response = await page.goto(`${base}${route}`, { waitUntil: 'networkidle', timeout: 30000 })
      check(response && response.status() === 200, `${route} returned ${response?.status()}`)
      check(await page.locator('html').getAttribute('dir') === 'rtl', `${route} should render RTL`)
      check(await page.locator('body').innerText().then(text => text.trim().length > 20), `${route} rendered no content`)
      if (['/', '/counselors', '/appointment'].includes(route)) await page.screenshot({ path: path.join(screenshots, `desktop-${name.replaceAll(' ', '-')}.png`), fullPage: true })
    })
  }

  await run('counselor details load from Laravel', async () => {
    await page.goto(`${base}/counselors`, { waitUntil: 'networkidle' })
    const profile = page.locator('a[href^="/counselors/"]').first()
    const href = await profile.getAttribute('href')
    check(href, 'counselor cards have no detail link')
    const response = await page.goto(`${base}${href}`, { waitUntil: 'networkidle' })
    check(response && response.status() === 200, `counselor profile returned ${response?.status()}`)
    check(await page.locator('h1').count() > 0, 'counselor detail has no heading')
  })

  await run('backend APIs load through rendered public site', async () => {
    const [counselors, services, posts] = await Promise.all([
      page.request.get(`${api}/counselors?per_page=5`, { headers: { Origin: base } }),
      page.request.get(`${api}/booking/services`, { headers: { Origin: base } }),
      page.request.get(`${api}/content/posts?per_page=5`, { headers: { Origin: base } }),
    ])
    check(counselors.status() === 200 && (await counselors.json()).data.length > 0, 'counselors API unavailable')
    check(services.status() === 200 && (await services.json()).data.length > 0, 'booking services API unavailable')
    check(posts.status() === 200 && (await posts.json()).data.length > 0, 'posts API unavailable')
    check(await page.locator('html').getAttribute('dir') === 'rtl', 'rendered app lost RTL')
  })

  for (const width of [768, 390]) {
    await run(`${width}px responsive public pages`, async () => {
      await page.setViewportSize({ width, height: 900 })
      for (const route of ['/', '/counselors', '/appointment', '/account/login']) {
        const response = await page.goto(`${base}${route}`, { waitUntil: 'networkidle', timeout: 30000 })
        check(response && response.status() === 200, `${route} failed at ${width}px`)
        check(await page.locator('html').getAttribute('dir') === 'rtl', `${route} lost RTL at ${width}px`)
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 2)
        check(!overflow, `${route} has horizontal overflow at ${width}px`)
      }
      await page.screenshot({ path: path.join(screenshots, `mobile-${width}.png`), fullPage: true })
    })
  }

  await run('client registration and authenticated account', async () => {
    await page.setViewportSize({ width: 1440, height: 1000 })
    const suffix = `${Date.now()}`
    testEmail = `e2e-${suffix}@example.test`
    await page.goto(`${base}/account/register`, { waitUntil: 'networkidle' })
    await page.getByLabel('نام', { exact: true }).fill('مراجع آزمون مرورگر')
    await page.getByLabel('ایمیل', { exact: true }).fill(testEmail)
    await page.getByLabel('شماره تماس', { exact: true }).fill(`0912${suffix.slice(-7)}`)
    await page.getByLabel('رمز عبور', { exact: true }).fill(testPassword)
    await page.getByLabel('تکرار رمز عبور', { exact: true }).fill(testPassword)
    await page.getByRole('button', { name: 'ثبت نام' }).click()
    await page.waitForURL('**/account/appointments', { timeout: 20000 })
    await page.reload({ waitUntil: 'networkidle' })
    check(await page.getByRole('heading', { name: 'نوبت‌های من' }).count() > 0, 'account did not persist across reload')
    check(await page.evaluate(() => !!sessionStorage.getItem('client-token')), 'client session token not persisted')
    await page.screenshot({ path: path.join(screenshots, 'desktop-client-account.png'), fullPage: true })
  })

  await run('client logout', async () => {
    check(await page.evaluate(() => !!sessionStorage.getItem('client-token')), 'no active token before logout')
    await page.getByRole('button', { name: 'خروج امن' }).click()
    await page.waitForFunction(() => !sessionStorage.getItem('client-token'), { timeout: 10000 })
    await page.waitForURL(`${base}/`, { timeout: 10000 })
    check(await page.evaluate(() => !sessionStorage.getItem('client-token')), 'session token remained after logout')
  })

  await run('client login and profile', async () => {
    await page.goto(`${base}/account/login`, { waitUntil: 'networkidle' })
    await page.getByLabel('ایمیل', { exact: true }).fill(testEmail)
    await page.getByLabel('رمز عبور', { exact: true }).fill(testPassword)
    await page.getByRole('button', { name: 'ورود' }).click()
    await page.waitForURL('**/account/appointments', { timeout: 20000 })
    await page.goto(`${base}/account/profile`, { waitUntil: 'networkidle' })
    await page.getByLabel('شماره تماس', { exact: true }).waitFor()
    check(await page.getByLabel('ایمیل', { exact: true }).inputValue() === testEmail, 'profile is not backed by authenticated API')
  })

  let bookingId = null
  let bookingReference = null
  await run('real booking, failed Fake Bank response, safe retry and successful payment', async () => {
    const [counselorResponse, serviceResponse] = await Promise.all([
      page.request.get(`${api}/counselors?per_page=100`), page.request.get(`${api}/booking/services`),
    ])
    const counselors = (await counselorResponse.json()).data
    const services = (await serviceResponse.json()).data
    let selection = null
    for (const counselor of counselors) {
      for (const service of services) {
        for (let offset = 1; offset <= 45 && !selection; offset++) {
          const date = tehranDate(new Date(Date.now() + offset * 86400000))
          const availability = await page.request.get(`${api}/booking/availability?counselor_id=${counselor.id}&service_id=${service.id}&date=${date}`)
          if (!availability.ok()) continue
          const body = await availability.json()
          const slot = (body.slots || []).find(item => item.available && new Date(item.starts_at).getTime() > Date.now())
          if (slot) selection = { counselor, service, date, slot }
        }
        if (selection) break
      }
      if (selection) break
    }
    check(selection, 'no real future availability in isolated test database')
    const query = new URLSearchParams({
      counselor: String(selection.counselor.id), service: String(selection.service.id),
      date: selection.date, start: selection.slot.starts_at,
    })
    await page.goto(`${base}/appointment?${query}`, { waitUntil: 'networkidle' })
    await page.getByRole('button', { name: 'ادامه به انتخاب خدمت' }).click()
    await page.locator('select').last().waitFor()
    await page.locator('select').last().selectOption(String(selection.service.id))
    await page.locator('input[type="date"]').fill(selection.date)
    await page.locator('label.slot').first().waitFor({ timeout: 15000 })
    await page.locator('label.slot').first().click()
    await page.getByRole('button', { name: 'بررسی جزئیات' }).click()
    await page.getByRole('button', { name: 'ثبت رزرو موقت' }).click()
    await page.waitForURL('**/fake-bank?**', { timeout: 30000 })
    check(await page.getByText('TEST BANK').count() === 1, 'fake checkout is not clearly labeled')
    await page.getByRole('button', { name: 'پرداخت ناموفق' }).waitFor({ timeout: 15000 })
    bookingReference = await page.locator('.receipt-row').first().innerText()
    await page.getByRole('button', { name: 'پرداخت ناموفق' }).click()
    await page.getByRole('heading', { name: 'پرداخت انجام نشد' }).waitFor()
    await page.getByRole('link', { name: 'مشاهده نوبت' }).click()
    await page.waitForURL('**/account/appointments/**')
    bookingId = Number(new URL(page.url()).pathname.split('/').at(-1))
    check(Number.isSafeInteger(bookingId) && bookingId > 0, 'booking id was not routed to client details')
    const clientToken = await page.evaluate(() => sessionStorage.getItem('client-token'))
    const failedBooking = await (await page.request.get(`${api}/booking/my-reservations/${bookingId}`, { headers: { Authorization: `Bearer ${clientToken}` } })).json()
    const failedPayments = await (await page.request.get(`${api}/booking/my-reservations/${bookingId}/payments`, { headers: { Authorization: `Bearer ${clientToken}` } })).json()
    check(failedBooking.data.status === 'held' && failedBooking.data.paid_irr === 0, `failed payment changed booking state: ${JSON.stringify(failedBooking.data)}`)
    check(failedPayments.data.some(payment => payment.status === 'failed'), 'failed attempt missing from payment history API')
    await page.getByRole('button', { name: /تلاش دوباره/ }).waitFor({ timeout: 15000 })
    await page.getByRole('button', { name: /تلاش دوباره/ }).click()
    await page.waitForURL('**/fake-bank?**', { timeout: 30000 })
    await page.getByRole('button', { name: 'پرداخت موفق' }).click()
    await page.getByRole('heading', { name: 'پرداخت با موفقیت تأیید شد' }).waitFor()
    check((await page.locator('body').innerText()).includes('شماره رسید'), 'persistent receipt number missing from result')
    const successBooking = await (await page.request.get(`${api}/booking/my-reservations/${bookingId}`, { headers: { Authorization: `Bearer ${clientToken}` } })).json()
    const successPayments = await (await page.request.get(`${api}/booking/my-reservations/${bookingId}/payments`, { headers: { Authorization: `Bearer ${clientToken}` } })).json()
    const successReceipts = await (await page.request.get(`${api}/booking/my-reservations/${bookingId}/receipts`, { headers: { Authorization: `Bearer ${clientToken}` } })).json()
    check(successBooking.data.status === 'confirmed' && successBooking.data.paid_irr > 0, 'successful payment did not confirm booking in API')
    check(successPayments.data.filter(payment => payment.status === 'verified').length === 1, 'success retry must create exactly one verified payment')
    check(successReceipts.data.length === 1, 'successful retry must create exactly one receipt')
    await page.getByRole('link', { name: 'مشاهده نوبت' }).click()
    await page.waitForURL('**/account/appointments/**')
    await page.getByText('تأیید شده', { exact: true }).first().waitFor()
    await page.screenshot({ path: path.join(screenshots, 'desktop-confirmed-booking.png'), fullPage: true })
  })

  await run('client appointments, payments, receipts and notifications read backend data', async () => {
    check(bookingId, 'booking flow did not create a booking')
    await page.goto(`${base}/account/appointments`, { waitUntil: 'networkidle' })
    check((await page.locator('body').innerText()).includes(String(bookingId)), 'booking absent from client appointments')
    await page.goto(`${base}/account/payments`, { waitUntil: 'networkidle' })
    check((await page.locator('body').innerText()).includes('fake_bank'), 'fake bank payment absent from account payment history')
    await page.goto(`${base}/account/receipts`, { waitUntil: 'networkidle' })
    check((await page.locator('body').innerText()).includes('رسید پرداخت'), 'receipt absent from account receipts')
    await page.goto(`${base}/account/notifications`, { waitUntil: 'networkidle' })
    check((await page.locator('body').innerText()).includes('پرداخت'), 'payment/confirmation notification absent')
  })

  await run('client rescheduling API persists the new appointment time', async () => {
    check(bookingId, 'booking flow did not create a booking')
    const token = await page.evaluate(() => sessionStorage.getItem('client-token'))
    const record = await (await page.request.get(`${api}/booking/my-reservations/${bookingId}`, { headers: { Authorization: `Bearer ${token}` } })).json()
    const oldStart = record.data.starts_at
    const [counselorResponse, serviceResponse] = await Promise.all([
      page.request.get(`${api}/counselors?per_page=100`), page.request.get(`${api}/booking/services`),
    ])
    const counselor = (await counselorResponse.json()).data.find(item => item.id === record.data.counselor_id)
    const service = (await serviceResponse.json()).data.find(item => item.id === record.data.booking_service_id)
    let nextSlot = null
    for (let offset = 1; offset <= 45 && !nextSlot; offset++) {
      const date = tehranDate(new Date(Date.now() + offset * 86400000))
      const response = await page.request.get(`${api}/booking/availability?counselor_id=${counselor.id}&service_id=${service.id}&date=${date}`)
      if (!response.ok()) continue
      const body = await response.json()
      nextSlot = (body.slots || []).find(item => item.available && item.starts_at !== oldStart)
    }
    check(nextSlot, 'no alternate slot available for reschedule')
    const response = await page.request.patch(`${api}/booking/my-reservations/${bookingId}/reschedule`, {
      headers: { Authorization: `Bearer ${token}`, Origin: base },
      data: { starts_at: nextSlot.starts_at },
    })
    check(response.status() === 200, `reschedule API returned ${response.status()}: ${await response.text()}`)
    const updated = await response.json()
    check(new Date(updated.data.starts_at).getTime() === new Date(nextSlot.starts_at).getTime(), `rescheduled time not persisted: ${updated.data.starts_at} vs ${nextSlot.starts_at}`)
  })

  await run('client reschedules from appointment detail UI', async () => {
    check(bookingId, 'booking flow did not create a booking')
    await page.goto(`${base}/account/appointments/${bookingId}`, { waitUntil: 'networkidle' })
    await page.getByRole('button', { name: 'تغییر زمان نوبت' }).click()
    const token = await page.evaluate(() => sessionStorage.getItem('client-token'))
    const record = await (await page.request.get(`${api}/booking/my-reservations/${bookingId}`, { headers: { Authorization: `Bearer ${token}` } })).json()
    let available = null
    for (let offset = 2; offset <= 45 && !available; offset++) {
      const date = tehranDate(new Date(Date.now() + offset * 86400000))
      const response = await page.request.get(`${api}/booking/availability?counselor_id=${record.data.counselor_id}&service_id=${record.data.booking_service_id}&date=${date}`)
      if (!response.ok()) continue
      const body = await response.json()
      const slot = (body.slots || []).find(item => item.available && new Date(item.starts_at).getTime() > Date.now())
      if (slot) available = { date, slot }
    }
    check(available, 'no available date found for UI reschedule')
    await page.locator('input[type="date"]').fill(available.date)
    await page.locator('select').last().waitFor({ timeout: 15000 })
    await page.locator('select').last().selectOption(available.slot.starts_at)
    await page.getByRole('button', { name: 'تأیید تغییر زمان' }).click()
    await page.getByText('زمان نوبت با موفقیت تغییر کرد.').waitFor()
    const updated = await (await page.request.get(`${api}/booking/my-reservations/${bookingId}`, { headers: { Authorization: `Bearer ${token}` } })).json()
    check(new Date(updated.data.starts_at).getTime() === new Date(available.slot.starts_at).getTime(), 'UI reschedule did not persist selected slot')
  })

  await run('client cancellation request records policy result', async () => {
    check(bookingId, 'booking flow did not create a booking')
    await page.goto(`${base}/account/appointments/${bookingId}`, { waitUntil: 'networkidle' })
    await page.getByRole('button', { name: 'ثبت درخواست' }).click()
    await page.getByRole('button', { name: 'تأیید درخواست' }).click()
    await page.getByText('درخواست لغو ثبت شد').waitFor()
  })

  await run('public page JavaScript and hydration errors', async () => {
    const significant = pageErrors.filter(message => !/Failed to load resource: net::ERR_ABORTED/.test(message))
    check(significant.length === 0, significant.slice(0, 5).join(' | '))
  })

  await browser.close()
  console.log(JSON.stringify({ passed, failed, apiResponses, pageErrors, failures, screenshots }, null, 2))
  if (failed) process.exitCode = 1
}

main().catch(error => { console.error(error); process.exit(1) })
