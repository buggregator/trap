var W=Object.defineProperty;var x=(t,e,n)=>e in t?W(t,e,{enumerable:!0,configurable:!0,writable:!0,value:n}):t[e]=n;var T=(t,e,n)=>(x(t,typeof e!="symbol"?e+"":e,n),n);import{l as b,R as N,a3 as P,a4 as J,J as A}from"./entry.8cc9a0a4.js";import{a as L,u as O,b as w,c as $}from"./file-attachment.vue.44781509.js";const j=()=>{const t=b(),{token:e}=t.$authToken,n={"X-Auth-Token":e},i=o=>`${N}/api/event${o?`/${o}`:"s"}`;return{getAll:()=>fetch(i(),{headers:n}).then(o=>o.json()).then(o=>o!=null&&o.data?o.data:(o==null?void 0:o.code)===403?(console.error("Forbidden"),[]):(console.error("Fetch Error"),[])).then(o=>o),getSingle:o=>fetch(i(o),{headers:n}).then(c=>c.json()).then(c=>c!=null&&c.data?c.data:null),deleteAll:()=>fetch(i(),{method:"DELETE",headers:n}).catch(o=>{console.error("Fetch Error",o)}),deleteList:o=>fetch(i(),{method:"DELETE",headers:n,body:JSON.stringify({uuids:o})}).catch(c=>{console.error("Fetch Error",c)}),deleteSingle:o=>fetch(i(o),{method:"DELETE",headers:n}).catch(c=>{console.error("Fetch Error",c)}),deleteByType:o=>fetch(i(),{method:"DELETE",headers:n,body:JSON.stringify({type:o})}).catch(c=>{console.error("Fetch Error",c)}),getEventRestUrl:i}},z={}.VITE_APP_MODE==="production",p=t=>{z||console.info(`[ApiConnection logger]:Centrifuge "${t[0]}" called with params: "${JSON.stringify(t[1])}"`)},a=class a{constructor(){T(this,"centrifuge");this.centrifuge=new P.Centrifuge(J),this.centrifuge.on("connected",e=>{p(["connected",e])}),this.centrifuge.on("publication",e=>{p(["publication",e])}),this.centrifuge.on("disconnected",e=>{p(["disconnected",e])}),this.centrifuge.on("error",e=>{p(["error",e])}),this.centrifuge.connect()}static getInstance(){return a.instance||(a.instance=new a),a.instance}getCentrifuge(){return this.centrifuge}};T(a,"instance");let I=a;const q=()=>({centrifuge:I.getInstance().getCentrifuge()});let C=!1;const U=()=>{const t=b(),{token:e}=t.$authToken,{centrifuge:n}=q(),i=L(),l=O(),{getAll:d,getSingle:y,deleteAll:E,deleteList:f,deleteSingle:h,deleteByType:o,getEventRestUrl:c}=j(),g=()=>l.isConnectedWS;C||((()=>{n.on("connected",()=>{l.addWSConnection()}),n.on("disconnected",()=>{l.removeWSConnection()}),n.on("error",()=>{l.removeWSConnection()}),n.on("message",()=>{l.addWSConnection()}),n.on("publication",r=>{var B,k;if(r.channel==="events"&&((B=r.data)==null?void 0:B.event)==="event.received"){const F=((k=r==null?void 0:r.data)==null?void 0:k.data)||null;i.addList([F])}})})(),C=!0);const S=r=>g()?n.rpc(`delete:api/event/${r}`,{token:e}):h(r);return{getEventsAll:d,getEvent:y,deleteEvent:S,deleteEventsAll:()=>g()?n.rpc("delete:api/events",{token:e}):E(),deleteEventsList:r=>r.length?r.length===1?S(r[0]):g()?n.rpc("delete:api/events",{uuids:r,token:e}):f(r):Promise.resolve(),deleteEventsByType:r=>g()?n.rpc("delete:api/events",{type:r,token:e}):o(r),rayStopExecution:r=>{n.rpc(`post:api/ray/locks/${r}`,{stop_execution:!0,token:e})},rayContinueExecution:r=>{n.rpc(`post:api/ray/locks/${r}`,{token:e})},getUrl:c}},M=t=>({id:t.uuid,type:"unknown",labels:[t.type],origin:null,serverName:"",date:t.timestamp?new Date(t.timestamp*1e3):null,payload:t.payload}),V=()=>{const t=L(),e=w(),n=$(),{lockedIds:i}=A(n),{events:l}=A(t),{deleteEventsAll:d,deleteEventsList:y,deleteEventsByType:E,getEventsAll:f,getEvent:h,getUrl:o}=U(),c=async s=>{await y(s)&&(t.removeByIds(s),e.removeByIds(s))};return{items:l,getItem:h,getUrl:o,getAll:()=>{f().then(s=>{s.length?(t.initialize(s),e.syncWithActive(s.map(({uuid:u})=>u))):(t.removeAll(),e.removeAll())}).catch(s=>{console.error("getAll err",s)})},removeAll:async()=>{if(i.value.length){const u=l.value.filter(({uuid:v})=>!i.value.includes(v)).map(({uuid:v})=>v);await c(u);return}await d()&&(t.removeAll(),e.removeAll())},removeByType:async s=>{if(i.value.length){const v=l.value.filter(({type:m,uuid:r})=>m===s&&!i.value.includes(r)).map(({uuid:m})=>m);await c(v);return}await E(s)&&(t.removeByType(s),e.removeByType(s))},removeById:async s=>{await c([s])}}},H=()=>{const t=w(),e=$(),{rayContinueExecution:n,rayStopExecution:i}=U(),{cachedIds:l}=A(t),{lockedIds:d}=A(e);return{normalizeUnknownEvent:M,events:V(),cachedEvents:{idsByType:l,stopUpdatesByType:t.setByType,runUpdatesByType:t.removeByType},lockedIds:{items:d,add:e.add,remove:e.remove},rayExecution:{continue:n,stop:i}}};export{H as u};