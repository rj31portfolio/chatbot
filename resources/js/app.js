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

const marketingMenu=document.querySelector('[data-marketing-menu]');
if(marketingMenu){
    const navigation=document.getElementById(marketingMenu.getAttribute('aria-controls'));
    const closeMenu=()=>{navigation.classList.remove('is-open');marketingMenu.setAttribute('aria-expanded','false');marketingMenu.setAttribute('aria-label','Open menu');};
    marketingMenu.addEventListener('click',()=>{const open=marketingMenu.getAttribute('aria-expanded')!=='true';navigation.classList.toggle('is-open',open);marketingMenu.setAttribute('aria-expanded',String(open));marketingMenu.setAttribute('aria-label',open?'Close menu':'Open menu');});
    navigation.querySelectorAll('a').forEach(link=>link.addEventListener('click',closeMenu));
    document.addEventListener('keydown',event=>{if(event.key==='Escape'&&marketingMenu.getAttribute('aria-expanded')==='true'){closeMenu();marketingMenu.focus();}});
    document.addEventListener('click',event=>{if(!event.target.closest('.marketing-header'))closeMenu();});
}

document.querySelectorAll('[data-auto-demo]').forEach(demo=>{
    const steps=[...demo.querySelectorAll('[data-demo-step]')];
    const status=demo.querySelector('[data-demo-status]');
    const controls=demo.querySelector('.demo-controls');
    const toggle=demo.querySelector('[data-demo-toggle]');
    const replay=demo.querySelector('[data-demo-replay]');
    const motionPreference=window.matchMedia('(prefers-reduced-motion: reduce)');
    const labels=['1 / 5 · A visitor asks about your services','2 / 5 · Your AI answers from business knowledge','3 / 5 · The visitor shares their contact details','4 / 5 · The enquiry is shared with your team','5 / 5 · A lead and follow-up task are ready'];
    let step=0,timer,paused=motionPreference.matches,visible=false;
    controls.hidden=false;
    const render=()=>{steps.forEach((message,index)=>message.hidden=index>step);status.textContent=labels[step];demo.classList.toggle('demo-is-running',!paused&&!motionPreference.matches);toggle.textContent=paused?'Play demo':'Pause demo';toggle.setAttribute('aria-pressed',String(paused));};
    const stop=()=>{clearTimeout(timer);timer=undefined;};
    const schedule=()=>{
        stop();
        if(paused||!visible||document.hidden)return;
        timer=setTimeout(()=>{step=(step+1)%steps.length;render();schedule();},step===steps.length-1?5000:2400);
    };
    if(motionPreference.matches){step=steps.length-1;render();}else{render();}
    toggle.addEventListener('click',()=>{paused=!paused;render();schedule();});
    replay.addEventListener('click',()=>{step=0;paused=false;render();schedule();});
    const observer=new IntersectionObserver(entries=>{visible=entries[0].isIntersecting;schedule();},{threshold:.1});observer.observe(demo);
    document.addEventListener('visibilitychange',schedule);
    motionPreference.addEventListener('change',()=>{paused=motionPreference.matches;if(paused)step=steps.length-1;render();schedule();});
});
