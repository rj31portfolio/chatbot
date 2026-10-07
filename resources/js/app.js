import Alpine from 'alpinejs';
window.Alpine=Alpine;
Alpine.start();

document.querySelectorAll('form[data-confirm]').forEach(form=>form.addEventListener('submit',e=>{if(!confirm(form.dataset.confirm))e.preventDefault();}));
document.querySelectorAll('[data-copy]').forEach(button=>button.addEventListener('click',async()=>{
    try{await navigator.clipboard.writeText(document.getElementById(button.dataset.copy).textContent);button.textContent='Copied!';}catch{button.textContent='Select the code and copy it manually.';}
}));

async function post(url,data){const response=await fetch(url,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},body:JSON.stringify(data)});const body=await response.json();if(!response.ok)throw new Error(Object.values(body.errors||{}).flat()[0]||body.message);return body.data;}
const tester=document.querySelector('[data-tester]');
if(tester){let session;const thread=tester.querySelector('.message-thread');const form=tester.querySelector('form');const input=form.querySelector('input');const button=form.querySelector('button');
    const bubble=(text,type)=>{const item=document.createElement('div');item.className='message-bubble '+type;item.textContent=text;thread.append(item);thread.scrollTop=thread.scrollHeight;};
    form.addEventListener('submit',async e=>{e.preventDefault();const message=input.value.trim();if(!message)return;button.disabled=true;input.value='';bubble(message,'visitor');try{session??=await post('/tester/session',{});const reply=await post('/tester/message',{...session,message});bubble(reply.message,'ai');document.getElementById('debug-intent').textContent=reply.intent;document.getElementById('debug-score').textContent=reply.score;const list=document.getElementById('debug-sources');list.replaceChildren();for(const source of reply.citations){const line=document.createElement('p');line.textContent=source.title;list.append(line);}if(!reply.citations.length)list.textContent='No relevant knowledge found.';}catch(error){bubble(error.message,'system');}finally{button.disabled=false;input.focus();}});
}

let razorpayScript;
function loadRazorpay(){
    if(window.Razorpay)return Promise.resolve();
    razorpayScript??=new Promise((resolve,reject)=>{const script=document.createElement('script');script.src='https://checkout.razorpay.com/v1/checkout.js';script.onload=resolve;script.onerror=()=>{script.remove();razorpayScript=null;reject(new Error('Checkout could not load. Please try again.'));};document.head.append(script);});
    return razorpayScript;
}
document.querySelectorAll('[data-checkout]').forEach(button=>button.addEventListener('click',async()=>{
    const buttons=document.querySelectorAll('[data-checkout]');
    const reset=()=>buttons.forEach(item=>item.disabled=false);
    buttons.forEach(item=>item.disabled=true);
    try{
        await loadRazorpay();
        const {order,key,prefill}=await post('/subscription/order',{plan_id:button.dataset.checkout,interval:document.getElementById('billing-interval').value,coupon:document.getElementById('coupon-code').value});
        const checkout=new window.Razorpay({key,order_id:order.id,amount:order.amount,currency:order.currency,prefill,name:document.title,description:'Prepaid business automation subscription',handler:async payment=>{try{await post('/subscription/verify',payment);location.reload();}catch(error){alert(error.message);reset();}},modal:{ondismiss:reset}});
        checkout.on('payment.failed',response=>{alert(response.error?.description||'Payment failed. Please try again.');reset();});
        checkout.open();
    }catch(error){alert(error.message||'Checkout could not be opened.');reset();}
}));

const expiryCounters=document.querySelectorAll('[data-expiry]');
function updateExpiryCounters(){
    expiryCounters.forEach(counter=>{
        const seconds=Math.max(0,Math.floor((Date.parse(counter.dataset.expiry)-Date.now())/1000));
        if(!Number.isFinite(seconds))return;
        if(seconds===0){counter.textContent='Expired ? Renew to continue';counter.closest('.subscription-banner')?.classList.add('subscription-warning');return;}
        const days=Math.floor(seconds/86400),hours=Math.floor(seconds%86400/3600),minutes=Math.floor(seconds%3600/60);
        counter.textContent=days+'d '+String(hours).padStart(2,'0')+'h '+String(minutes).padStart(2,'0')+'m '+String(seconds%60).padStart(2,'0')+'s remaining';
    });
}
if(expiryCounters.length){updateExpiryCounters();setInterval(updateExpiryCounters,1000);}
document.querySelectorAll('[data-price-interval]').forEach(button=>button.addEventListener('click',()=>{
    document.querySelectorAll('[data-price-interval]').forEach(item=>{const selected=item===button;item.classList.toggle('selected',selected);item.setAttribute('aria-pressed',String(selected));});
    document.querySelectorAll('[data-monthly][data-yearly]').forEach(price=>price.textContent=price.dataset[button.dataset.priceInterval]);
    document.querySelectorAll('[data-price-period]').forEach(label=>label.textContent=button.dataset.priceInterval==='yearly'?'/ year':'/ month');
}));
