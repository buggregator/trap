import{u as j}from"./C_H-m1dF.js";import{h as Y}from"./BmkTCIb-.js";import{d as B,o as s,a as d,F as y,r as C,t as p,g as i,b as n,_ as H,l as R,c as h,f as o,w as l,u as e,s as z,e as S,h as _,R as D,p as M,i as P,j as U,k as q,m as K,n as W}from"./BFT9qVMJ.js";import{P as X,u as Z}from"./Dw0BM4Dm.js";import{b as G,C as A,T as J,a as f,u as O}from"./BdYR1MGi.js";import{q as Q,W as $}from"./CJ1Q_tph.js";import{F as x}from"./CUPvwW3p.js";import"./CsfJzf5f.js";import{u as L}from"./wd-3U9fd.js";import"./UQLe1jqg.js";const ee={class:"smtp-page-addresses"},te={key:0,class:"smtp-page-addresses__item-name"},se=B({__name:"smtp-page-addresses",props:{addresses:{}},setup(c){return(a,T)=>(s(),d("div",ee,[(s(!0),d(y,null,C(a.addresses,v=>(s(),d("div",{key:v.email,class:"smtp-page-addresses__item"},[v.name?(s(),d("span",te,p(v.name),1)):i("",!0),n("span",null,p(v.email),1)]))),128))]))}}),k=H(se,[["__scopeId","data-v-217deb6c"]]),ae=c=>(M("data-v-6dc92cf7"),c=c(),P(),c),ne={ref:"main",class:"smtp-page"},oe={class:"smtp-page__main"},le={class:"smtp-page__header"},de={class:"smtp-page__header-title"},ce={class:"smtp-page__header-meta"},re={class:"smtp-page__header-date"},ue={class:"smtp-page__sender"},pe={class:"smtp-page__sender-title"},ie={class:"smtp-page__sender-address"},me={class:"smtp-page__body"},_e=["innerHTML"],ve={class:"mb-5"},he={class:"flex gap-x-3"},fe=ae(()=>n("h3",{class:"mb-3 font-bold"},"Email Headers",-1)),ye=B({__name:"smtp-page",props:{event:{},attachments:{default:()=>[]},htmlSource:{}},setup(c){const a=c,T=R(a.htmlSource||a.event.payload.html),v=h(()=>[{title:"From",address:a.event.payload.from},{title:"To",address:a.event.payload.to},{title:"CC",address:a.event.payload.cc},{title:"BCC",address:a.event.payload.bcc},{title:"Reply to",address:a.event.payload.reply_to}]),b=h(()=>{var t,r;return((t=a.event.payload)==null?void 0:t.html)!==void 0&&((r=a.event.payload)==null?void 0:r.html)!==""}),g=h(()=>{var t,r;return((t=a.event.payload)==null?void 0:t.text)!==void 0&&((r=a.event.payload)==null?void 0:r.text)!==""}),E=h(()=>a.event.payload),I=h(()=>Y(a.event.date).format("DD.MM.YYYY HH:mm:ss"));return(t,r)=>(s(),d("div",ne,[n("main",oe,[n("header",le,[n("h2",de,p(E.value.subject),1),n("div",ce,[n("span",re,p(I.value),1)])]),n("section",ue,[(s(!0),d(y,null,C(v.value,u=>(s(),d(y,null,[(s(!0),d(y,null,C(u.address,m=>(s(),d("div",{key:`${u.title}-${m.email}`,class:z(["smtp-page__sender-item",`smtp-page__sender-${u.title.toLowerCase()}`])},[n("div",pe,p(u.title),1),n("div",ie,[m.name?(s(),d(y,{key:0},[S(p(m.name)+" ["+p(m.email)+"] ",1)],64)):(s(),d(y,{key:1},[S(p(m.email),1)],64))])],2))),128))],64))),256))]),n("section",me,[o(e(Q),{options:{useUrlFragment:!1}},{default:l(()=>[b.value?(s(),_(e($),{key:0,id:"html-preview",name:"Preview",suffix:"<span class='smtp-page__body-tab-badge'>HTML</span>"},{default:l(()=>[o(e(G),{device:"tablet"},{default:l(()=>[n("div",{innerHTML:T.value},null,8,_e)]),_:1})]),_:1})):i("",!0),b.value?(s(),_(e($),{key:1,name:"HTML"},{default:l(()=>[o(e(A),{language:"html",class:"tab-preview-code",code:t.event.payload.html},null,8,["code"])]),_:1})):i("",!0),g.value?(s(),_(e($),{key:2,name:"Text"},{default:l(()=>[o(e(A),{language:"html",class:"max-w-full tab-preview-code",code:t.event.payload.text},null,8,["code"])]),_:1})):i("",!0),t.attachments.length?(s(),_(e($),{key:3,name:`Attachments (${t.attachments.length})`},{default:l(()=>[n("section",ve,[n("div",he,[(s(!0),d(y,null,C(t.attachments,u=>(s(),_(e(x),{key:u.uuid,"event-id":t.event.id,attachment:u,"download-url":`${e(D)}/api/smtp/${t.event.id}/attachments/${u.uuid}`},null,8,["event-id","attachment","download-url"]))),128))])])]),_:1},8,["name"])):i("",!0),o(e($),{name:"Raw"},{default:l(()=>[o(e(A),{class:"tab-preview-code",language:"html",code:t.event.payload.raw},null,8,["code"])]),_:1}),o(e($),{name:"Tech Info"},{default:l(()=>[n("section",null,[fe,o(e(J),null,{default:l(()=>[o(e(f),{title:"Id"},{default:l(()=>[S(p(t.event.payload.id),1)]),_:1}),o(e(f),{title:"Subject"},{default:l(()=>[S(p(t.event.payload.subject),1)]),_:1}),o(e(f),{title:"From"},{default:l(()=>[o(e(k),{addresses:t.event.payload.from},null,8,["addresses"])]),_:1}),o(e(f),{title:"To"},{default:l(()=>[o(e(k),{addresses:t.event.payload.to},null,8,["addresses"])]),_:1}),t.event.payload.cc.length?(s(),_(e(f),{key:0,title:"Cc"},{default:l(()=>[o(e(k),{addresses:t.event.payload.cc},null,8,["addresses"])]),_:1})):i("",!0),t.event.payload.bcc.length?(s(),_(e(f),{key:1,title:"Bcc"},{default:l(()=>[o(e(k),{addresses:t.event.payload.bcc},null,8,["addresses"])]),_:1})):i("",!0),t.event.payload.reply_to.length?(s(),_(e(f),{key:2,title:"Reply to"},{default:l(()=>[o(e(k),{addresses:t.event.payload.reply_to},null,8,["addresses"])]),_:1})):i("",!0)]),_:1})])]),_:1})]),_:1})])])],512))}}),ge=H(ye,[["__scopeId","data-v-6dc92cf7"]]);function $e(c){return c.replace(/./gm,a=>a.match(/[a-z0-9\s]+/i)?a:`&#${a.charCodeAt(0)};`)}const F=c=>(M("data-v-c40c37a1"),c=c(),P(),c),be={class:"smtp-event"},ke={key:0,class:"smtp-event__loading"},Te=F(()=>n("div",null,null,-1)),we=F(()=>n("div",null,null,-1)),Se=F(()=>n("div",null,null,-1)),Ce=[Te,we,Se],Re={class:"smtp-event__body"},Ee=B({__name:"[id]",setup(c){const{normalizeSmtpEvent:a}=L(),{params:T}=U(),{$authToken:v}=W(),b=q(),g=T.id;j(`SMTP > ${g} | Buggregator`);const{events:E}=O(),{getAttachments:I}=L(),t=R(!1),r=R(null),u=R([]),m=h(()=>r.value?a(r.value):null),N=h(()=>u.value),V=h(()=>`<iframe srcdoc="${$e(r.value.payload.html)}"/>`);return K(async()=>{t.value=!0,await Z(E.getUrl(g),{headers:{"X-Auth-Token":v.token||""},onResponse({response:{_data:w}}){r.value=w,t.value=!1},onResponseError(){b.push("/404")},onRequestError(){b.push("/404")}},"$KoVaT6Zs8R"),await I(g).then(w=>{u.value=w})}),(w,Ae)=>(s(),d("main",be,[o(e(X),{title:"Smtp","event-id":e(g)},null,8,["event-id"]),t.value&&!m.value?(s(),d("div",ke,Ce)):i("",!0),n("div",Re,[m.value?(s(),_(e(ge),{key:0,event:m.value,attachments:N.value,"html-source":V.value},null,8,["event","attachments","html-source"])):i("",!0)])]))}}),ze=H(Ee,[["__scopeId","data-v-c40c37a1"]]);export{ze as default};
