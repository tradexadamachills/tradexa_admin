(() => {
  const root = document.querySelector('[data-market-dashboard]');
  if (!root) return;
  let pair='BTCUSDT', interval='1h', snapshot=null, pending=null;
  const $ = selector => root.querySelector(selector);
  const all = selector => root.querySelectorAll(selector);
  const fmt = (value, digits=2) => Number(value).toLocaleString('en-US',{minimumFractionDigits:digits,maximumFractionDigits:digits});
  const node = (tag, attributes={}) => {const item=document.createElementNS('http://www.w3.org/2000/svg',tag); for (const [name,value] of Object.entries(attributes)) item.setAttribute(name,String(value)); return item;};
  function text(svg,x,y,value,attributes={}) {const item=node('text',{x,y,...attributes});item.textContent=value;svg.append(item);}
  function drawChart(bars) {
    const svg=$('[data-candle-chart]');svg.replaceChildren();
    const width=744, top=14, bottom=264, low=Math.min(...bars.map(b=>b.low)), high=Math.max(...bars.map(b=>b.high));
    const padding=(high-low)*.12||high*.005, min=low-padding,max=high+padding;
    const y=value=>top+(max-value)/(max-min)*(bottom-top);
    for(let i=0;i<6;i++) {const py=top+i*(bottom-top)/5;svg.append(node('line',{x1:0,x2:width,y1:py,y2:py,stroke:'#213456','stroke-dasharray':'3 4'}));text(svg,width+10,py+4,fmt(max-i*(max-min)/5),{fill:'#a9b6d6','font-size':10});}
    bars.forEach((bar,index)=>{const x=8+index*(width-16)/bars.length, color=bar.close>=bar.open?'#26b99a':'#ec6b7b';svg.append(node('line',{x1:x,x2:x,y1:y(bar.high),y2:y(bar.low),stroke:color,'stroke-width':1}));svg.append(node('rect',{x:x-3,y:y(Math.max(bar.open,bar.close)),width:6,height:Math.max(1,Math.abs(y(bar.open)-y(bar.close))),fill:color,rx:.6})); if(index%12===0) text(svg,x,bottom+24,new Date(bar.time).toLocaleString([], {month:'short',day:'numeric',hour:'2-digit'}),{fill:'#8b9bb7','font-size':9});});
    const close=bars.at(-1).close;svg.append(node('line',{x1:0,x2:width,y1:y(close),y2:y(close),stroke:'#4c8dff','stroke-dasharray':'4 4',opacity:.6}));
    all('[data-spark]').forEach(s=>s.replaceChildren());
    const spark=root.querySelector(`[data-spark="${pair}"]`), closes=bars.slice(-30).map(b=>b.close), lo=Math.min(...closes),hi=Math.max(...closes);
    if(spark) spark.append(node('polyline',{points:closes.map((v,i)=>`${i*180/(closes.length-1)},${32-(v-lo)/(hi-lo||1)*26}`).join(' '),fill:'none',stroke:'currentColor','stroke-width':2}));
  }
  function render(data) {
    snapshot=data;
    for(const quote of data.quotes) {
      all(`[data-price="${quote.symbol}"]`).forEach(e=>e.textContent=fmt(quote.price));
      all(`[data-change="${quote.symbol}"]`).forEach(e=>{e.textContent=`${quote.change>=0?'+':''}${fmt(quote.change)}%`;e.classList.toggle('negative',quote.change<0);});
    }
    const quote=data.quotes.find(q=>q.symbol===pair);
    all('[data-current-price]').forEach(e=>e.textContent=fmt(quote.price));
    all('[data-current-change]').forEach(e=>{e.textContent=`${quote.change>=0?'+':''}${fmt(quote.change)}%`;e.classList.toggle('negative',quote.change<0);});
    $('[data-ticket-price]').textContent=fmt(quote.price);
    $('[data-book-pair]').textContent=pair.replace('USDT','/USDT');
    all('[data-market-time]').forEach(e=>e.textContent=`Provider time: ${new Date(quote.time).toLocaleTimeString()}`);
    for(const side of ['asks','bids']) {
      const container=$(`[data-book-${side}]`);container.replaceChildren();
      const rows=side==='asks'?[...data.book[side]].reverse():data.book[side];
      for(const [price,quantity] of rows) {const row=document.createElement('div'); row.className='book-row';for(const value of [fmt(price),fmt(quantity,5),fmt(price*quantity)]) {const span=document.createElement('span');span.textContent=value;row.append(span);}container.append(row);}
    }
    drawChart(data.candles);$('[data-market-state]').hidden=true;
    all('[data-select-pair]').forEach(e=>e.setAttribute('aria-pressed',String(e.dataset.selectPair===pair)));
  }
  function clear(message) {
    snapshot=null;$('[data-candle-chart]').replaceChildren();all('[data-spark]').forEach(e=>e.replaceChildren());
    all('[data-price],[data-change],[data-current-price],[data-current-change],[data-ticket-price]').forEach(e=>e.textContent='--');
    all('[data-book-asks],[data-book-bids]').forEach(e=>e.replaceChildren());
    const state=$('[data-market-state]');state.textContent=message;state.hidden=false;all('[data-market-time]').forEach(e=>e.textContent='No current provider data');
  }
  async function load() {
    pending?.abort();const controller=new AbortController();pending=controller;
    try {
      const url=new URL(root.dataset.endpoint,location.origin);url.searchParams.set('symbol',pair);url.searchParams.set('interval',interval);
      const response=await fetch(url,{headers:{Accept:'application/json'},signal:AbortSignal.any([controller.signal,AbortSignal.timeout(22000)]),cache:'no-store'});
      if(!response.ok) throw new Error('Market data unavailable');
      const data=await response.json();
      if(controller.signal.aborted) return;
      if(data.symbol!==pair||data.interval!==interval||!data.quotes?.length||data.quotes.some(q=>Date.now()-q.time>60000||q.time-Date.now()>30000)) throw new Error('Stale response');
      render(data);
    } catch(error) {if(!controller.signal.aborted) clear('Market data unavailable. Retrying shortly.');}
    finally {if(pending===controller) pending=null;}
  }
  function select(value) {pair=value;$('[data-market-symbol]').value=pair;clear('Loading selected market...');void load();}
  $('[data-market-symbol]').addEventListener('change',event=>select(event.target.value));
  all('[data-select-pair]').forEach(button=>button.addEventListener('click',()=>select(button.dataset.selectPair)));
  all('[data-interval]').forEach(button=>button.addEventListener('click',()=>{interval=button.dataset.interval;all('[data-interval]').forEach(e=>e.setAttribute('aria-pressed',String(e===button)));clear('Loading selected timeframe...');void load();}));
  void load();
  setInterval(()=>{if(!pending&&!document.hidden) void load();if(snapshot&&snapshot.quotes.some(q=>Date.now()-q.time>60000)) clear('Market data expired. Refreshing...');},15000);
})();
