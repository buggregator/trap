import{u as M}from"./C_H-m1dF.js";import{m as A}from"./D2aBVH6c.js";import{u as N}from"./DHfIEqZc.js";import{T as I,a as T,u as Y}from"./BdYR1MGi.js";import"./BmkTCIb-.js";import{d as P,c as y,o as a,a as n,b as s,t as p,F as m,r as $,f as h,w as v,u as _,h as b,H,I as V,g as k,e as w,p as S,i as B,_ as C,j as F,k as L,l as x,m as O,n as U}from"./BFT9qVMJ.js";import{P as j,u as z}from"./Dw0BM4Dm.js";import"./CsfJzf5f.js";const D=o=>(S("data-v-c958e41d"),o=o(),B(),o),X={ref:"main",class:"ray"},q={class:"ray__in"},G={class:"ray__header"},J={class:"ray__header-title"},K={class:"ray__header-meta"},Q={class:"ray__header-date"},W={class:"ray__body"},Z={key:0},ee={class:"ray__body"},te=D(()=>s("h3",{class:"ray__body-text"},"Origin",-1)),se={class:"ray__body"},ae=D(()=>s("h3",{class:"ray__body-text"},"Meta",-1)),oe=P({__name:"ray-page",props:{event:{}},setup(o){const{COMPONENT_TYPE_MAP:f}=N(),r=o,g=y(()=>{const t=String(r.event.payload.payloads[0].type||"Unknown type");return t[0].toUpperCase()+t.slice(1)}),u=y(()=>A(r.event.date).format("DD.MM.YYYY HH:mm:ss")),i=y(()=>{var t,c,e;return(t=r.event)!=null&&t.meta?[`text-${(c=r.event.meta)==null?void 0:c.size}`,`text-${(e=r.event.meta)==null?void 0:e.color}-500`]:[]}),d=t=>f[t];return(t,c)=>(a(),n("div",X,[s("main",q,[s("header",G,[s("h2",J,p(g.value),1),s("div",K,[s("span",Q,p(u.value),1)])]),s("section",W,[(a(!0),n(m,null,$(t.event.payload.payloads,e=>(a(),n(m,{key:`${e.type}-${e.origin?e.origin.line_number:""}`},[e.type&&d(e.type)?(a(),n("div",Z,[(a(),b(V(d(e.type).view),H({ref_for:!0},{...d(e.type).getProps(e)},{class:i.value}),null,16,["class"]))])):k("",!0)],64))),128))]),s("section",ee,[te,h(_(I),{class:"ray__body-table"},{default:v(()=>[(a(!0),n(m,null,$(t.event.payload.payloads[0].origin,(e,l)=>(a(),b(_(T),{key:l,title:String(l)},{default:v(()=>[w(p(e),1)]),_:2},1032,["title"]))),128))]),_:1})]),s("section",se,[ae,h(_(I),{class:"ray__body-table"},{default:v(()=>[(a(!0),n(m,null,$(t.event.payload.meta,(e,l)=>(a(),b(_(T),{key:l,title:String(l)},{default:v(()=>[w(p(e),1)]),_:2},1032,["title"]))),128))]),_:1})])])],512))}}),ne=C(oe,[["__scopeId","data-v-c958e41d"]]),E=o=>(S("data-v-395c0a32"),o=o(),B(),o),re={class:"ray-dump"},ce={key:0,class:"ray-dump__loading"},_e=E(()=>s("div",null,null,-1)),le=E(()=>s("div",null,null,-1)),ie=E(()=>s("div",null,null,-1)),de=[_e,le,ie],ue={key:1,class:"ray-dump__body"},pe=P({__name:"[id]",setup(o){const{normalizeRayEvent:f}=N(),{params:r}=F(),{$authToken:g}=U(),u=L(),i=r.id;M(`Ray Dumo > ${i} | Buggregator`);const{events:d}=Y(),t=x(!1),c=x(null),e=y(()=>c.value?f(c.value):null);return O(async()=>{t.value=!0,await z(d.getUrl(i),{headers:{"X-Auth-Token":g.token||""},onResponse({response:{_data:R}}){c.value=R,t.value=!1},onResponseError(){u.push("/404")},onRequestError(){u.push("/404")}},"$ALNIlIXj0A")}),(R,me)=>(a(),n("main",re,[h(_(j),{title:"Ray Dump","event-id":_(i)},null,8,["event-id"]),t.value&&!e.value?(a(),n("div",ce,de)):k("",!0),e.value?(a(),n("div",ue,[h(_(ne),{event:e.value},null,8,["event"])])):k("",!0)]))}}),Ee=C(pe,[["__scopeId","data-v-395c0a32"]]);export{Ee as default};