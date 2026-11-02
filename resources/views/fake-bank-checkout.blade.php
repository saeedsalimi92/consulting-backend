<!doctype html>
<html lang="fa" dir="rtl">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>درگاه آزمایشی پرداخت</title>
<style>body{font-family:Tahoma,Arial,sans-serif;background:#f3f5f8;color:#18212f;margin:0;display:grid;min-height:100vh;place-items:center}.card{background:white;padding:2rem;border-radius:14px;box-shadow:0 8px 32px #18212f18;width:min(440px,calc(100% - 48px))}h1{font-size:1.3rem}.badge{display:inline-block;background:#fff1cc;color:#805b00;padding:.4rem .7rem;border-radius:20px;font-size:.85rem}.row{display:flex;justify-content:space-between;border-bottom:1px solid #eee;padding:1rem 0}.actions{display:grid;gap:.7rem;margin-top:1.5rem}button{border:0;border-radius:8px;padding:.9rem;font-size:1rem;cursor:pointer}.success{background:#16834a;color:white}.failure{background:#a52a2a;color:white}#message{min-height:1.5rem;color:#a52a2a}</style></head>
<body><main class="card"><span class="badge">محیط محلی / پرداخت آزمایشی</span><h1>درگاه بانکی آزمایشی</h1>
<div class="row"><span>شناسه پرداخت</span><strong>{{ $payment['payment_id'] }}</strong></div>
<div class="row"><span>رزرو</span><strong>{{ $payment['booking_reference'] }}</strong></div>
<div class="row"><span>مبلغ</span><strong>{{ number_format($payment['amount_irr']) }} ریال</strong></div>
<div id="message" role="status"></div><div class="actions">
<button class="success" type="button" onclick="submitResult('success')">پرداخت موفق</button>
<button class="failure" type="button" onclick="submitResult('failure')">پرداخت ناموفق</button>
</div></main>
<script>
const resultUrl=@json($resultUrl), returnUrl=@json($returnUrl), paymentId=@json($payment['payment_id']), checkoutToken=@json($token);
async function submitResult(result){const message=document.getElementById('message');message.textContent='در حال ثبت نتیجه…';document.querySelectorAll('button').forEach(b=>b.disabled=true);try{const response=await fetch(resultUrl,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({token:checkoutToken,result})});const body=await response.json();if(!response.ok)throw new Error(body.message||'ثبت نتیجه ناموفق بود');const target=new URL(returnUrl);target.searchParams.set('payment_id',paymentId);target.searchParams.set('status',body.data.status==='verified'?'success':'failure');window.location.assign(target.toString());}catch(e){message.textContent=e.message;document.querySelectorAll('button').forEach(b=>b.disabled=false)}}
</script></body></html>
