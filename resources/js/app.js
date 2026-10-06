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
document.querySelectorAll('[data-checkout]').forEach(button=>button.addEventListener('click',async()=>{button.disabled=true;try{const {order,key}=await post('/subscription/order',{plan_id:button.dataset.checkout,interval:document.getElementById('billing-interval').value});if(!window.Razorpay)await new Promise((resolve,reject)=>{const script=document.createElement('script');script.src='https://checkout.razorpay.com/v1/checkout.js';script.onload=resolve;script.onerror=reject;document.head.append(script);});new window.Razorpay({key,order_id:order.id,amount:order.amount,currency:order.currency,name:document.title,handler:async payment=>{try{await post('/subscription/verify',payment);location.reload();}catch(error){alert(error.message);}},modal:{ondismiss:()=>button.disabled=false}}).open();}catch(error){alert(error.message||'Checkout could not be opened.');button.disabled=false;}}));
